<?php

namespace App\Catalog\Services;

use App\Core\Exceptions\ApiRefused;
use App\Catalog\Models\Product;
use App\Catalog\Models\ProductImage;
use App\Core\Support\UploadProbe;
use App\Core\Support\UploadQuota;
use App\Identity\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Putting a product's photographs on disk, and taking them off it.
 *
 * The media rules live here rather than in the endpoint (§36): the count
 * ceiling, the tenant-wide byte ceiling, what a file's bytes have to say before
 * a byte of it is stored, where the copy is written, and which copy is the
 * cover. The controller keeps the questions it can answer — is this product
 * yours, is the batch well-formed — and hands the rest over.
 *
 * The extension and the stored `mime_type` both come from the one probe that
 * gated the file. Probing again inside the write step is how a stored row ends
 * up describing a type the check never approved, so a file is examined exactly
 * once per upload and the result is carried through.
 */
class ProductImageFilingService
{
    /**
     * Accept a batch of photographs for one product.
     *
     * A batch because that is how a seller works — the pallet, the carton, the
     * label — and because some of it may be refused while the rest is kept. The
     * two ceilings are checked over the whole batch before any file reaches the
     * disk: an upload that will be refused must not leave a half-written set
     * behind.
     *
     * @param  array<int, UploadedFile>  $uploadedFiles
     * @return array{images: list<ProductImage>, rejected: list<array{file: string, reason: string}>}
     */
    public function attach(Product $product, User $uploader, array $uploadedFiles): array
    {
        $limits = config('hassal.uploads.images');

        $existing = ProductImage::query()->where('product_id', $product->id)->count();

        if ($existing + count($uploadedFiles) > $limits['max_per_product']) {
            throw new ApiRefused(
                'وصل المنتج للحد الأقصى من الصور (' . $limits['max_per_product'] . ')',
                'IMAGE_LIMIT_REACHED',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['max_per_product' => $limits['max_per_product'], 'current' => $existing]
            );
        }

        // A shared catalog row (no owning organization) has no tenant quota to
        // charge against.
        if ($product->organization_id !== null) {
            $incoming = 0;
            foreach ($uploadedFiles as $uploaded) {
                $incoming += (int) ($uploaded->getSize() ?: 0);
            }

            $quota = UploadQuota::check($product->organization_id, $incoming);

            if (!$quota['allowed']) {
                throw new ApiRefused(
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

        foreach ($uploadedFiles as $uploaded) {
            // Size before type: a refusal on bytes should not spend a read of
            // the file's head, and the ceiling is the cheaper answer.
            if ($uploaded->getSize() > $limits['max_bytes']) {
                $rejected[] = [
                    'file'   => $uploaded->getClientOriginalName(),
                    'reason' => 'too_large:' . $uploaded->getSize() . ' > ' . $limits['max_bytes'],
                ];
                continue;
            }

            // Both detectors have to agree before a byte is stored.
            // `getMimeType()` alone already ignored the client's declared type,
            // which is the protection people usually mean by "MIME checking";
            // what it did not do is notice a file whose leading bytes and
            // sampled type name different formats — the shape of a polyglot.
            // See App\Core\Support\UploadProbe.
            $probe = UploadProbe::isAcceptable($uploaded, $limits['accepted_mimes']);

            if (!$probe['ok']) {
                // Saying which types are accepted is cheaper for both sides
                // than a refusal that leaves the seller guessing.
                $rejected[] = [
                    'file'   => $uploaded->getClientOriginalName(),
                    'reason' => $probe['reason'] === 'unsupported_type'
                        ? 'unsupported_type:' . $probe['mime'] . '؛ المدعوم: ' . implode(', ', $limits['accepted_mimes'])
                        : $probe['reason'] . ':' . $probe['mime'],
                ];
                continue;
            }

            $stored[] = $this->put($uploaded, $product, $uploader, $probe);
        }

        // Nothing accepted but everything rejected is a failure the caller has
        // to see; a 201 with an empty list would read as success.
        if ($stored === []) {
            throw new ApiRefused(
                'لم يُقبل أي ملف: ' . ($rejected[0]['reason'] ?? 'نوع غير مدعوم'),
                'NO_IMAGE_ACCEPTED',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['rejected' => $rejected]
            );
        }

        // The first photograph of a product with none becomes its cover, so a
        // listing is never left pointing at nothing while holding a file.
        if (!$product->images()->where('is_cover', true)->exists()) {
            $stored[0]->update(['is_cover' => true]);
        }

        return ['images' => $stored, 'rejected' => $rejected];
    }

    /**
     * Choose the cover and set the order a buyer sees.
     *
     * Making a copy the cover un-makes the others: a product with two covers
     * would render one listing twice depending on which row a reader reached
     * first.
     *
     * @param  array<string, mixed>  $attributes  already validated — `is_cover`, `sort_order`
     */
    public function arrange(ProductImage $image, array $attributes): ProductImage
    {
        $changes = array_intersect_key($attributes, array_flip(['is_cover', 'sort_order']));

        if (filter_var($changes['is_cover'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            ProductImage::query()->where('product_id', $image->product_id)->update(['is_cover' => false]);
        }

        $image->update($changes);

        return $image->fresh();
    }

    /**
     * Delete a photograph and the file behind it.
     *
     * The row goes because it describes a file that no longer exists. The
     * product's `image_url` column is not touched: nothing here wrote it, and
     * editing data the platform never created is how a delete endpoint ends up
     * destroying an operator's own record.
     */
    public function discard(ProductImage $image): void
    {
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
            $next = ProductImage::query()
                ->where('product_id', $productId)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->first();

            $next?->update(['is_cover' => true]);
        }
    }

    /**
     * Write one accepted file and record it.
     *
     * @param  array{ok: bool, mime: string, extension: ?string, reason: ?string}  $probe
     */
    private function put(UploadedFile $uploaded, Product $product, User $uploader, array $probe): ProductImage
    {
        // The extension comes from the bytes that were detected, never from the
        // name the sender chose. The accepted-type check compares a real mime
        // against a list of images, so a file carrying JPEG bytes and named
        // `photo.php` passes it — and would then be written onto a public disk
        // under a name that asks the web server to execute it.
        $extension = $probe['extension'] ?: 'img';

        $uuid = (string) Str::uuid();
        $owner = $product->organization_id ?? 'shared';
        $path = 'products/' . $owner . '/' . $product->id . '/' . $uuid . '.' . $extension;

        // A generated name: the original filename is metadata on the row, never
        // part of the path.
        Storage::disk('public')->putFileAs(dirname($path), $uploaded, basename($path));

        // Dimensions come from the file that was actually received. A failed
        // read leaves them null — the image still serves — rather than guessing
        // an aspect ratio the client would then lay out wrong.
        $dimensions = @getimagesize($uploaded->getRealPath() ?: '');

        $maxOrder = (int) ProductImage::query()->where('product_id', $product->id)->max('sort_order');

        return ProductImage::create([
            'product_id'      => $product->id,
            'organization_id' => $product->organization_id,
            'disk'            => 'public',
            'path'            => $path,
            'original_name'   => Str::limit($uploaded->getClientOriginalName(), 250, ''),
            'mime_type'       => $probe['mime'],
            'size_bytes'      => $uploaded->getSize() ?: 0,
            'width'           => $dimensions !== false ? $dimensions[0] : null,
            'height'          => $dimensions !== false ? $dimensions[1] : null,
            'sort_order'      => $maxOrder + 1,
            'is_cover'        => false,
            'uploaded_by'     => $uploader->id,
        ]);
    }
}
