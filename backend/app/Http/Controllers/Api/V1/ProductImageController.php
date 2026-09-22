<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\TenantContext;
use App\Support\UploadQuota;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Photographs of a product, uploaded as files.
 *
 * The rules that are enforced here rather than in the client (§36):
 *
 *   * The product must belong to the organization in context. A rival holding
 *     the same `catalog.manage` permission cannot attach, read or delete media
 *     it did not upload — a photograph of stacked stock is also a photograph of
 *     somebody's warehouse.
 *   * The type and size ceilings come from `config('hassal.uploads.images')`, a
 *     deployment limit, not from a number typed into this file.
 *   * The stored path is generated. A client-supplied filename never reaches
 *     the disk, so `../../index.php` is not a thing this endpoint can be asked
 *     to write.
 *
 * `Product::$image_url` is left alone. These rows are the real media set; a
 * single free-text URL column has no upload path behind it and cannot express a
 * cover photo plus the four others.
 */
class ProductImageController extends Controller
{
    public function index(Request $request, int $productId): JsonResponse
    {
        $product = $this->ownedProduct($request, $productId);

        if ($product instanceof JsonResponse) {
            return $product;
        }

        $images = ProductImage::query()
            ->where('product_id', $product->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return ApiResponse::success($images->map(fn (ProductImage $i) => $this->shape($i))->all(), 'صور المنتج');
    }

    /**
     * Attach one or more photographs.
     *
     * Accepts a batch because that is how a seller works — the pallet, the
     * carton, the label — and returns the resulting set so the client does not
     * have to refetch to know what it now has.
     */
    public function store(Request $request, int $productId): JsonResponse
    {
        $product = $this->ownedProduct($request, $productId);

        if ($product instanceof JsonResponse) {
            return $product;
        }

        $limits = config('hassal.uploads.images');

        $request->validate([
            'images'   => ['required', 'array', 'min:1', 'max:' . $limits['max_per_product']],
            'images.*' => ['required', 'file'],
        ]);

        $existing = ProductImage::query()->where('product_id', $product->id)->count();

        if ($existing + count($request->file('images')) > $limits['max_per_product']) {
            return ApiResponse::error(
                'وصل المنتج للحد الأقصى من الصور (' . $limits['max_per_product'] . ')',
                'IMAGE_LIMIT_REACHED',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['max_per_product' => $limits['max_per_product'], 'current' => $existing]
            );
        }

        // The tenant-wide byte ceiling is checked over the whole batch before any
        // file reaches the disk: an upload that will be refused must not leave a
        // half-written set behind. A shared catalog row (no owning organization)
        // has no tenant quota to charge against.
        if ($product->organization_id !== null) {
            $incoming = 0;
            foreach ($request->file('images') as $uploaded) {
                $incoming += (int) ($uploaded->getSize() ?: 0);
            }
            $quota = UploadQuota::check($product->organization_id, $incoming);
            if (!$quota['allowed']) {
                return ApiResponse::error(
                    'تجاوزت مساحة التخزين المخصصة للمنشأة (' . $quota['quota_bytes'] . ' بايت)',
                    'UPLOAD_QUOTA_EXCEEDED',
                    Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
                    [
                        'quota_bytes'    => $quota['quota_bytes'],
                        'used_bytes'     => $quota['used_bytes'],
                        'incoming_bytes' => $quota['incoming_bytes'],
                    ]
                );
            }
        }

        $stored = [];
        $rejected = [];

        foreach ($request->file('images') as $uploaded) {
            $refusal = $this->refuse($uploaded, $limits);

            if ($refusal !== null) {
                $rejected[] = ['file' => $uploaded->getClientOriginalName(), 'reason' => $refusal];
                continue;
            }

            $stored[] = $this->put($uploaded, $product, $request, $limits);
        }

        // Nothing accepted but everything rejected is a failure the caller has
        // to see; a 201 with an empty list would read as success.
        if ($stored === []) {
            return ApiResponse::error(
                'لم يُقبل أي ملف: ' . ($rejected[0]['reason'] ?? 'نوع غير مدعوم'),
                'NO_IMAGE_ACCEPTED',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['rejected' => $rejected]
            );
        }

        // The first photograph of a product with none becomes its cover, so a
        // listing is never left pointing at nothing while holding a file.
        if (!$product->images()->where('is_cover', true)->exists() && $stored !== []) {
            $stored[0]->update(['is_cover' => true]);
        }

        return ApiResponse::success([
            'images'   => array_map(fn (ProductImage $i) => $this->shape($i), $stored),
            'rejected' => $rejected,
        ], 'تم رفع الصور', Response::HTTP_CREATED);
    }

    /**
     * Choose the cover and set the order a buyer sees.
     */
    public function update(Request $request, int $productId, int $imageId): JsonResponse
    {
        $image = $this->ownedImage($request, $productId, $imageId);

        if ($image instanceof JsonResponse) {
            return $image;
        }

        $validated = $request->validate([
            'is_cover'   => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:999'],
        ]);

        if (filter_var($validated['is_cover'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            ProductImage::query()->where('product_id', $image->product_id)->update(['is_cover' => false]);
        }

        $image->update(array_intersect_key($validated, array_flip(['is_cover', 'sort_order'])));

        return ApiResponse::success($this->shape($image->fresh()), 'تم تحديث الصورة');
    }

    /**
     * Delete a photograph, and the file behind it.
     *
     * The row goes because it describes a file that no longer exists. The
     * product's `image_url` column is not touched: nothing here wrote it, and
     * editing data the platform never created is how a delete endpoint ends up
     * destroying an operator's own record.
     */
    public function destroy(Request $request, int $productId, int $imageId): JsonResponse
    {
        $image = $this->ownedImage($request, $productId, $imageId);

        if ($image instanceof JsonResponse) {
            return $image;
        }

        $disk = Storage::disk($image->disk);
        $wasCover = (bool) $image->is_cover;
        $productId = $image->product_id;

        if ($disk->exists($image->path)) {
            $disk->delete($image->path);
        }

        $image->delete();

        // A product left with photographs but no cover would render blank in a
        // listing, so the cover follows the first surviving image.
        if ($wasCover) {
            $next = ProductImage::query()->where('product_id', $productId)->orderBy('sort_order')->orderBy('id')->first();
            $next?->update(['is_cover' => true]);
        }

        return ApiResponse::success(['id' => $image->id, 'deleted' => true], 'تم حذف الصورة');
    }

    /**
     * @param  array<string, mixed>  $limits
     * @return array<string, mixed>
     */
    private function shape(ProductImage $image): array
    {
        return [
            'id'            => $image->id,
            'product_id'    => $image->product_id,
            'url'           => $image->url(),
            'is_cover'      => $image->is_cover,
            'sort_order'    => $image->sort_order,
            'mime_type'     => $image->mime_type,
            'size_bytes'    => $image->size_bytes,
            'width'         => $image->width,
            'height'        => $image->height,
            'original_name' => $image->original_name,
            'uploaded_at'   => $image->created_at,
        ];
    }

    /**
     * @param  array<string, mixed>  $limits
     */
    private function refuse(\Illuminate\Http\UploadedFile $file, array $limits): ?string
    {
        $mime = $file->getMimeType() ?: 'unknown';

        if (!in_array($mime, $limits['accepted_mimes'], true)) {
            // Saying which types are accepted is cheaper for both sides than a
            // refusal that leaves the seller guessing.
            return 'unsupported_type:' . $mime . '؛ المدعوم: ' . implode(', ', $limits['accepted_mimes']);
        }

        if ($file->getSize() > $limits['max_bytes']) {
            return 'too_large:' . $file->getSize() . ' > ' . $limits['max_bytes'];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $limits
     */
    private function put(\Illuminate\Http\UploadedFile $uploaded, Product $product, Request $request, array $limits): ProductImage
    {
        // The extension comes from the bytes that were detected, never from the
        // name the sender chose. The accepted-type check above compares a real
        // mime against a list of images, so a file carrying JPEG bytes and
        // named `photo.php` passes it — and would then be written onto a public
        // disk under a name that asks the web server to execute it.
        $extension = match ($uploaded->getMimeType()) {
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/jpeg' => 'jpg',
            default      => 'img',
        };

        $uuid = (string) Str::uuid();
        $owner = $product->organization_id ?? 'shared';
        $path = 'products/' . $owner . '/' . $product->id . '/' . $uuid . '.' . $extension;

        // `storeAs` with a generated name: the original filename is metadata on
        // the row, never part of the path.
        Storage::disk('public')->putFileAs(
            dirname($path),
            $uploaded,
            basename($path)
        );

        // Dimensions come from the file that was actually received. A failed
        // read leaves them null — the image still serves — rather than guessing
        // an aspect ratio the client would then lay out wrong.
        $dimensions = @getimagesize($uploaded->getRealPath() ?: '');

        $maxOrder = (int) ProductImage::query()->where('product_id', $product->id)->max('sort_order');

        return ProductImage::create([
            'product_id'    => $product->id,
            'organization_id' => $product->organization_id,
            'disk'          => 'public',
            'path'          => $path,
            'original_name' => Str::limit($uploaded->getClientOriginalName(), 250, ''),
            'mime_type'     => $uploaded->getMimeType() ?: 'application/octet-stream',
            'size_bytes'    => $uploaded->getSize() ?: 0,
            'width'         => $dimensions !== false ? $dimensions[0] : null,
            'height'        => $dimensions !== false ? $dimensions[1] : null,
            'sort_order'    => $maxOrder + 1,
            'is_cover'      => false,
            'uploaded_by'   => $request->user()->id,
        ]);
    }

    /**
     * The product, but only if the caller's organization owns it.
     *
     * @return Product|JsonResponse
     */
    private function ownedProduct(Request $request, int $productId): Product|JsonResponse
    {
        $product = Product::find($productId);

        if (!$product) {
            return ApiResponse::error('المنتج غير موجود', 'NOT_FOUND', Response::HTTP_NOT_FOUND);
        }

        if (!$request->user()->hasRole('SuperAdmin') && $product->organization_id !== TenantContext::id()) {
            // Refuse without confirming whether the product exists at all in
            // the message: the shape of another business's catalog is not
            // public information.
            return ApiResponse::error('لا يمكنك إدارة صور هذا المنتج', 'FORBIDDEN', Response::HTTP_FORBIDDEN);
        }

        return $product;
    }

    /**
     * @return ProductImage|JsonResponse
     */
    private function ownedImage(Request $request, int $productId, int $imageId): ProductImage|JsonResponse
    {
        $product = $this->ownedProduct($request, $productId);

        if ($product instanceof JsonResponse) {
            return $product;
        }

        $image = ProductImage::query()
            ->where('id', $imageId)
            ->where('product_id', $product->id)
            ->first();

        // The image is looked up inside the product the caller is allowed to
        // touch, so an id from another listing cannot be reached even by an
        // organization that owns a different product.
        if (!$image) {
            return ApiResponse::error('الصورة غير موجودة', 'NOT_FOUND', Response::HTTP_NOT_FOUND);
        }

        return $image;
    }
}
