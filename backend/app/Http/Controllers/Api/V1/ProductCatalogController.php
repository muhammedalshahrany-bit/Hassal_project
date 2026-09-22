<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ProductCatalogController extends Controller
{
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

    /**
     * Which products this request may see.
     *
     * `organization_id` is nullable, and that null is the whole design: a
     * product with no owner is a platform-level reference entry that any
     * business may list against, while a product with an owner is that
     * business' own SKU book — its packaging conventions, its internal codes.
     *
     * Before this rule existed the endpoint answered with every row in the
     * table, so a business that opened the catalog saw another business'
     * inventory naming in it. Returning your own shelf plus the public
     * vocabulary is the same result set for a solo platform user and no
     * longer a window into a competitor.
     */
    protected function applyCatalogVisibility($query, Request $request): void
    {
        if ($request->user()?->hasRole('SuperAdmin')) {
            return;
        }

        $organizationId = TenantContext::id();

        $query->where(function ($q) use ($organizationId) {
            $q->whereNull('organization_id');
            if ($organizationId) {
                $q->orWhere('organization_id', $organizationId);
            }
        });
    }

    public function products(Request $request): JsonResponse
    {
        $query = Product::with(['category', 'brand', 'variants'])->where('is_active', true);
        $this->applyCatalogVisibility($query, $request);

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

        $visibleOne = Product::query()->whereKey($product->id);
        $this->applyCatalogVisibility($visibleOne, $request);

        if (!$visibleOne->exists()) {
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

        $organizationId = TenantContext::id();

        // A product records who owns it, so an unowned row is by definition a
        // platform reference entry. Only the platform may write one: a business
        // creating a product without a resolved organization would otherwise be
        // publishing its SKU to every other business on the marketplace.
        //
        // `catalog.manage` is granted by a membership row now, and a membership
        // row is what resolves the organization in the first place, so a
        // business account reaching this line means the tenant middleware did
        // not run — which is the reason the guard stays rather than being
        // deleted as unreachable. An unaffiliated account never gets here at all;
        // the route refuses it for lacking the permission.
        if (!$organizationId && !$request->user()->hasRole('SuperAdmin')) {
            return ApiResponse::error(
                'مطلوب منشأة نشطة لإضافة أصناف إليها',
                'ORGANIZATION_CONTEXT_REQUIRED',
                Response::HTTP_FORBIDDEN
            );
        }

        // `product_variants.sku` is UNIQUE and the default variant inherits the
        // product code, so a repeated code has to be answered as a validation
        // failure. It used to reach MySQL and come back as a 500.
        $sku = $validated['sku'] ?? null;
        if ($sku !== null && ProductVariant::where('sku', $sku)->exists()) {
            return ApiResponse::error(
                'رمز الصنف مستخدم بالفعل',
                'VALIDATION_ERROR',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['sku' => ['رمز الصنف مستخدم بالفعل في الأصناف القائمة']]
            );
        }

        $product = Product::create(array_merge($validated, [
            'organization_id' => $organizationId,
        ]));

        // Create default variant
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => $sku ?? ('SKU-' . rand(100000, 999999)),
            'name_ar' => $product->name_ar,
            'name_en' => $product->name_en ?? $product->name_ar,
            'packaging_spec' => $product->package_size,
            'is_active' => true,
        ]);

        return ApiResponse::success($product->load(['variants', 'category', 'brand']), 'Product created successfully', Response::HTTP_CREATED);
    }

    /**
     * Correct a product this business owns.
     *
     * Two sets of rows are refused here rather than ignored: another business'
     * catalog, and the platform's shared reference entries. The second is the
     * subtler one — `organization_id` null means every business lists against
     * that row, so letting one of them rename it would rewrite other people's
     * vocabulary.
     *
     * `sku` is not editable. The code a warehouse scans lives on the variant
     * that was created with this product, and editing a product code without
     * editing the variant under it would leave two answers to the same scan.
     */
    public function updateProduct(Request $request, int $id): JsonResponse
    {
        $product = Product::find($id);
        if (!$product) {
            return ApiResponse::error('الصنف غير موجود', 'PRODUCT_NOT_FOUND', Response::HTTP_NOT_FOUND);
        }

        $isSuperAdmin = $request->user()->hasRole('SuperAdmin');

        if (!$isSuperAdmin) {
            $organizationId = TenantContext::id();

            if ($product->organization_id === null) {
                return ApiResponse::error(
                    'مرجع مشترك من المنصة ولا يمكن لمنشأة تعديله',
                    'PLATFORM_REFERENCE_NOT_EDITABLE',
                    Response::HTTP_FORBIDDEN
                );
            }

            if ($organizationId === null || (int) $product->organization_id !== (int) $organizationId) {
                return ApiResponse::error(
                    'ليس لديك صلاحية تعديل هذا الصنف',
                    'PRODUCT_NOT_OWNED',
                    Response::HTTP_FORBIDDEN
                );
            }
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

        $product->fill($validated)->save();

        return ApiResponse::success($product->fresh()->load(['variants', 'category', 'brand']), 'تم تحديث الصنف بنجاح');
    }
}
