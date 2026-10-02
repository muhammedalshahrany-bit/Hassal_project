<?php

namespace App\Catalog\Controllers;

use App\Core\Exceptions\ApiRefused;
use App\Catalog\Models\Product;
use App\Catalog\Models\ProductImage;
use App\Catalog\Services\ProductImageFilingService;
use App\Core\Http\ApiResponse;
use App\Core\Http\Controllers\Controller;
use App\Core\Tenant\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Photographs of a product, uploaded as files.
 *
 * What is decided here is who may ask, and whether the request is shaped
 * right. What a file has to be before it is stored, where it goes, how many a
 * product may hold and which one is its cover is media law and lives in
 * App\Catalog\Services\ProductImageFilingService.
 *
 * The tenant rule the endpoints share: the product must belong to the
 * organization in context. A rival holding the same `catalog.manage` permission
 * cannot attach, read or delete media it did not upload — a photograph of
 * stacked stock is also a photograph of somebody's warehouse.
 *
 * `Product::$image_url` is left alone. These rows are the real media set; a
 * single free-text URL column has no upload path behind it and cannot express a
 * cover photo plus the four others.
 */
class ProductImageController extends Controller
{
    public function __construct(
        private readonly ProductImageFilingService $filing,
    ) {
    }

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
     * Returns the resulting set plus whatever the media rules turned down, so
     * the client does not have to refetch to know what it now has — and cannot
     * mistake a partly-rejected batch for a clean one.
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

        try {
            $result = $this->filing->attach($product, $request->user(), $request->file('images'));
        } catch (ApiRefused $refused) {
            return $this->refused($refused);
        }

        return ApiResponse::success([
            'images'   => array_map(fn (ProductImage $i) => $this->shape($i), $result['images']),
            'rejected' => $result['rejected'],
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

        return ApiResponse::success(
            $this->shape($this->filing->arrange($image, $validated)),
            'تم تحديث الصورة'
        );
    }

    /**
     * Delete a photograph, and the file behind it.
     */
    public function destroy(Request $request, int $productId, int $imageId): JsonResponse
    {
        $image = $this->ownedImage($request, $productId, $imageId);

        if ($image instanceof JsonResponse) {
            return $image;
        }

        $this->filing->discard($image);

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
     * @return Product|JsonResponse
     */
    private function ownedProduct(Request $request, int $productId): Product|JsonResponse
    {
        $product = Product::find($productId);

        if (!$product) {
            return ApiResponse::error('المنتج غير موجود', 'NOT_FOUND', Response::HTTP_NOT_FOUND);
        }

        if (!$request->user()->isPlatformOperator() && $product->organization_id !== TenantContext::id()) {
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
