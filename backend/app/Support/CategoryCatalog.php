<?php

namespace App\Support;

/**
 * The food-sector classification HASSAL starts from (§1 of the product brief).
 *
 * One list, used by both the installer and the reference seeder. Two earlier
 * copies had drifted apart, so an install and a `db:seed` produced different
 * catalogues.
 *
 * No brands live here. A brand belongs to the business that supplies it: the
 * platform previously shipped Almarai, Savola, Nadec, Al Alali and Goody as
 * pre-invented records, which were both a regional identity this product does
 * not have and data nobody had supplied. Businesses register their own brands
 * through the catalog endpoints.
 */
final class CategoryCatalog
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function defaults(): array
    {
        return [
            ['code' => 'DAIRY',         'slug' => 'dairy-beverages',      'name_ar' => 'الألبان والعصائر والمشروبات',        'name_en' => 'Dairy & Beverages',          'icon' => 'Milk',      'sort_order' => 1],
            ['code' => 'DRY_GOODS',     'slug' => 'dry-grains',           'name_ar' => 'الحبوب والبقوليات والمواد الجافة',   'name_en' => 'Dry Goods & Grains',         'icon' => 'Wheat',     'sort_order' => 2],
            ['code' => 'OILS_FATS',     'slug' => 'oils-ghee',            'name_ar' => 'الزيوت والدهون والسمن',               'name_en' => 'Oils & Ghee',                'icon' => 'Droplet',   'sort_order' => 3],
            ['code' => 'CANNED',        'slug' => 'canned-preserved',     'name_ar' => 'المعلبات والمحفوظات الغذائية',        'name_en' => 'Canned & Preserved Foods',   'icon' => 'Package',   'sort_order' => 4],
            ['code' => 'FROZEN',        'slug' => 'frozen-chilled',       'name_ar' => 'المجمدات واللحوم والدواجن',           'name_en' => 'Frozen & Poultry',           'icon' => 'Snowflake', 'sort_order' => 5],
            ['code' => 'SWEETS_SNACKS', 'slug' => 'confectionery-snacks', 'name_ar' => 'الحلويات والمقرمشات والشوكولاتة',     'name_en' => 'Confectionery & Snacks',     'icon' => 'Cookie',    'sort_order' => 6],
            ['code' => 'BAKERY_RAW',    'slug' => 'bakery-raw-materials', 'name_ar' => 'مستلزمات المخابز والمواد الأولية',   'name_en' => 'Bakery & Raw Ingredients',   'icon' => 'Cake',      'sort_order' => 7],
            ['code' => 'CLEANING',      'slug' => 'cleaning',             'name_ar' => 'منتجات التنظيف',                       'name_en' => 'Cleaning Products',        'icon' => 'Spray',     'sort_order' => 8],
            ['code' => 'PERSONAL_CARE', 'slug' => 'personal-care',        'name_ar' => 'العناية الشخصية',                       'name_en' => 'Personal Care',            'icon' => 'Soap',      'sort_order' => 9],
        ];
    }
}
