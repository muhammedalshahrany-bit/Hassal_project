<?php

namespace App\Catalog\Controllers;

use App\Core\Exceptions\ApiRefused;
use App\Catalog\Models\Brand;
use App\Catalog\Models\Category;
use App\Catalog\Models\Product;
use App\Catalog\Models\ProductVariant;
use App\Catalog\Services\ProductCatalogService;
use App\Core\Http\ApiResponse;
use App\Core\Http\Controllers\Controller;
use App\Core\Tenant\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The catalog's front door: the shared vocabulary, each business' own shelf, and
 * the writes that open or correct a product line.
 *
 * Which rows a reader may see is a property of the product itself and is stated
 * once as `Product::scopeVisibleTo()`. Whether a write is allowed, what a
 * repeated SKU means, and what arrives beside a new product is catalog law and
 * lives in App\Catalog\Services\ProductCatalogService. This class resolves the
 * request, asks those two questions, and shapes the answer.
 */
class ProductCatalogController extends Controller
{
    public function __construct(
        private readonly ProductCatalogService $catalog,
    ) {
    }

    public function categories(): JsonResponse
    {
        $categories = Category::where('is_active', true)->whereNull('parent_id')->with('children')->orderBy('sort_order')->get();

        return ApiResponse::success($categories, 'Categories retrieved successfully');
    }

    public function brands(): JsonResponse
    {
        $brands = Brand::where('is_active', true)->orderBy('name_ar')->get();

        return ApiResponse::success($brands, 'Brands retrieved successfully');
    }

    public function products(Request $request): JsonResponse
    {
        $query = Product::with(['category', 'brand', 'variants'])
            ->where('is_active', true)
            ->visibleTo($this->actingOrganization($request), $request->user()?->isPlatformOperator());

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->input('category_id'));
        }

        if ($request->filled('brand_id')) {
            $query->where('brand_id', $request->input('brand_id'));
        }

        if ($request->filled('search')) {
            $s = $request->input('search');
            $query->where(function ($q) use ($s) {
                $q->where('name_ar', 'like', "%{$s}%")
                  ->orWhere('name_en', 'like', "%{$s}%")
                  ->orWhere('sku', 'like', "%{$s}%")
                  ->orWhere('barcode', 'like', "%{$s}%");
            });
        }

        $products = $query->paginate(25);

        return ApiResponse::paginated($products, 'Products retrieved successfully');
    }

    public function variants(Request $request, int $id): JsonResponse
    {
        // A variant belongs to a product, so the product decides who may read
        // it. Without this check the numeric id of any other business' product
        // was enough to enumerate its SKUs and barcodes.
        $product = Product::find($id);

        if (!$product) {
            return ApiResponse::error('الصنف غير موجود', 'PRODUCT_NOT_FOUND', Response::HTTP_NOT_FOUND);
        }

        $visible = Product::query()
            ->whereKey($product->id)
            ->visibleTo($this->actingOrganization($request), $request->user()?->isPlatformOperator())
            ->exists();

        if (!$visible) {
            return ApiResponse::error(
                'ليس لديك صلاحية الوصول لأصناف هذا المنتج',
                'VARIANT_SOURCE_NOT_ACCESSIBLE',
                Response::HTTP_FORBIDDEN
            );
        }

        $variants = ProductVariant::where('product_id', $id)
            ->where('is_active', true)
            ->orderBy('name_ar')
            ->get();

        return ApiResponse::success($variants, 'Product variants retrieved successfully');
    }

    public function storeProduct(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category_id' => ['required', 'exists:categories,id'],
            'brand_id' => ['nullable', 'exists:brands,id'],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:100'],
            'unit' => ['required', 'string'],
            'package_size' => ['nullable', 'string'],
            'temperature_class' => ['nullable', 'string'],
        ]);

        try {
            $product = $this->catalog->register(
                $validated,
                $this->actingOrganization($request),
                $request->user()->isPlatformOperator()
            );
        } catch (ApiRefused $refused) {
            return $this->refused($refused);
        }

        return ApiResponse::success(
            $product->load(['variants', 'category', 'brand']),
            'Product created successfully',
            Response::HTTP_CREATED
        );
    }

    /**
     * Correct a product this business owns.
     */
    public function updateProduct(Request $request, int $id): JsonResponse
    {
        $product = Product::find($id);

        if (!$product) {
            return ApiResponse::error('الصنف غير موجود', 'PRODUCT_NOT_FOUND', Response::HTTP_NOT_FOUND);
        }

        $isSuperAdmin = $request->user()->isPlatformOperator();

        try {
            $this->catalog->assertEditable($product, $this->actingOrganization($request), $isSuperAdmin);
        } catch (ApiRefused $refused) {
            return $this->refused($refused);
        }

        $validated = $request->validate([
            'category_id' => ['sometimes', 'required', 'exists:categories,id'],
            'brand_id' => ['nullable', 'exists:brands,id'],
            'name_ar' => ['sometimes', 'required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'unit' => ['sometimes', 'required', 'string', 'max:50'],
            'package_size' => ['nullable', 'string', 'max:100'],
            'temperature_class' => ['nullable', 'string', 'max:50'],
        ]);

        return ApiResponse::success(
            $this->catalog->edit($product, $validated)->load(['variants', 'category', 'brand']),
            'تم تحديث الصنف بنجاح'
        );
    }

    /**
     * The business whose shelf this request is reading or writing, or null for
     * the platform's own shared vocabulary.
     */
    private function actingOrganization(Request $request): ?int
    {
        $organizationId = TenantContext::id();

        return $organizationId === null ? null : (int) $organizationId;
    }

}
