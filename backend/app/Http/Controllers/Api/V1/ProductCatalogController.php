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

    public function products(Request $request): JsonResponse
    {
        $query = Product::with(['category', 'brand', 'variants'])->where('is_active', true);

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

    public function variants(int $id): JsonResponse
    {
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

        $product = Product::create(array_merge($validated, [
            'organization_id' => TenantContext::id(),
        ]));

        // Create default variant
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => $product->sku ?? ('SKU-' . rand(100000, 999999)),
            'name_ar' => $product->name_ar,
            'name_en' => $product->name_en ?? $product->name_ar,
            'packaging_spec' => $product->package_size,
            'is_active' => true,
        ]);

        return ApiResponse::success($product->load(['variants', 'category', 'brand']), 'Product created successfully', Response::HTTP_CREATED);
    }
}
