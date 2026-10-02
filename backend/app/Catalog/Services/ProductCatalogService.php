<?php

namespace App\Catalog\Services;

use App\Core\Exceptions\ApiRefused;
use App\Catalog\Models\Product;
use App\Catalog\Models\ProductVariant;
use Symfony\Component\HttpFoundation\Response;

/**
 * Writing the catalog: opening a product line and correcting one.
 *
 * Three rules live here rather than in an endpoint:
 *
 *   * A product records who owns it, so an unowned row is by definition a
 *     platform reference entry. Only the platform may write one: a business
 *     creating a product without a resolved organization would otherwise be
 *     publishing its SKU to every other business on the marketplace.
 *
 *     `catalog.manage` is granted by a membership row now, and a membership row
 *     is what resolves the organization in the first place, so a business
 *     account reaching this line means the tenant middleware did not run —
 *     which is the reason the guard stays rather than being deleted as
 *     unreachable. An unaffiliated account never gets here at all; the route
 *     refuses it for lacking the permission.
 *
 *   * `product_variants.sku` is UNIQUE and the default variant inherits the
 *     product code, so a repeated code has to be answered as a validation
 *     failure. It used to reach MySQL and come back as a 500.
 *
 *   * Two sets of rows are refused on edit rather than ignored: another
 *     business' catalog, and the platform's shared reference entries. The second
 *     is the subtler one — `organization_id` null means every business lists
 *     against that row, so letting one of them rename it would rewrite other
 *     people's vocabulary.
 *
 * `sku` is not editable. The code a warehouse scans lives on the variant that
 * was created with this product, and editing a product code without editing the
 * variant under it would leave two answers to the same scan.
 */
class ProductCatalogService
{
    /**
     * Open a product and the one variant every listing is later priced against.
     *
     * @param  array<string, mixed>  $validated
     */
    public function register(array $validated, ?int $organizationId, bool $isPlatformStaff): Product
    {
        if (!$organizationId && !$isPlatformStaff) {
            throw new ApiRefused(
                'مطلوب منشأة نشطة لإضافة أصناف إليها',
                'ORGANIZATION_CONTEXT_REQUIRED',
                Response::HTTP_FORBIDDEN
            );
        }

        $sku = $validated['sku'] ?? null;

        if ($sku !== null && ProductVariant::where('sku', $sku)->exists()) {
            throw new ApiRefused(
                'رمز الصنف مستخدم بالفعل',
                'VALIDATION_ERROR',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['sku' => ['رمز الصنف مستخدم بالفعل في الأصناف القائمة']]
            );
        }

        $product = Product::create(array_merge($validated, [
            'organization_id' => $organizationId,
        ]));

        // The product arrives with a default variant because nothing trades
        // against a product: offers, reservations and deals all point at a
        // variant, so a catalog row with no variant under it is a shelf nobody
        // can put a price on.
        ProductVariant::create([
            'product_id'     => $product->id,
            'sku'            => $sku ?? ('SKU-' . rand(100000, 999999)),
            'name_ar'        => $product->name_ar,
            'name_en'        => $product->name_en ?? $product->name_ar,
            'packaging_spec' => $product->package_size,
            'is_active'      => true,
        ]);

        return $product;
    }

    /**
     * May this reader correct this product at all?
     *
     * Asked before the form is validated, because a business that has no right
     * to touch a row should not be told which of its fields it typed wrong.
     */
    public function assertEditable(Product $product, ?int $organizationId, bool $isPlatformStaff): void
    {
        if ($isPlatformStaff) {
            return;
        }

        if ($product->organization_id === null) {
            throw new ApiRefused(
                'مرجع مشترك من المنصة ولا يمكن لمنشأة تعديله',
                'PLATFORM_REFERENCE_NOT_EDITABLE',
                Response::HTTP_FORBIDDEN
            );
        }

        if ($organizationId === null || (int) $product->organization_id !== (int) $organizationId) {
            throw new ApiRefused(
                'ليس لديك صلاحية تعديل هذا الصنف',
                'PRODUCT_NOT_OWNED',
                Response::HTTP_FORBIDDEN
            );
        }
    }

    /**
     * Apply a correction to the product's own naming and handling fields.
     *
     * @param  array<string, mixed>  $validated
     */
    public function edit(Product $product, array $validated): Product
    {
        $product->fill($validated)->save();

        return $product->fresh();
    }
}
