<?php

namespace App\Marketplace\Support;

use App\Marketplace\Models\Offer;
use Illuminate\Validation\Rule;

/**
 * What a legal listing input looks like, written down once.
 *
 * Three doors take listing fields — creating one, editing one, and searching for
 * one — and for a while each of them carried its own copy of the field list. That
 * is how a form ends up offering something the server refuses, or a limit exists
 * on the shelf and not on the edit: two lists describing one subject cannot be
 * kept in step by hand.
 *
 * The accepted *values* are the model's, not this file's: `opportunity_reason` is
 * `Offer::OPPORTUNITY_REASONS` and `delivery_terms` is `Offer::DELIVERY_TERMS`,
 * the same arrays the client is offered at cold start by
 * `App\Auth\Services\BootstrapService`. A state a validator would take but the
 * model does not name is still the bug this ordering exists to prevent.
 *
 * These are input rules and nothing more. Whether a listing may be written at
 * all — which business owns it, what state it is in, whether that state may still
 * change — is answered by `OfferWritingService` and `OfferLifecycleService`, which
 * are what a second door into a listing would meet too. A controller asks its
 * guard, then validates with these, in that order: a refusal about a permission
 * has to be the answer before a complaint about a field, or a caller who may not
 * write is told their prose was too long.
 */
final class OfferInputRules
{
    /**
     * Filing a new listing.
     *
     * `as_draft` decides only whether the row is published afterwards; the fields
     * a listing is made of are required either way, because a draft with no price
     * is not a draft, it is a note.
     *
     * The two quantity rules that mention each other (`gte:min_order_quantity`)
     * can only be checked here, where both halves arrive in one request. An edit
     * gets that comparison from `OfferWritingService::update()`, which reads the
     * other halves off the row — see `update()`.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function store(): array
    {
        return array_merge(self::fields(edit: false), [
            'warehouse_id'       => ['required', 'exists:warehouses,id'],
            'product_variant_id' => ['required', 'exists:product_variants,id'],
            // Where the goods sit and which goods they are are chosen once, with
            // the listing: neither may be answered by a row the seller does not
            // own, and `exists:` is the only half of that this layer can check —
            // ownership of the warehouse is the writing service's question.
            'category_id'        => ['nullable', 'exists:categories,id'],
            'currency_code'      => ['nullable', 'string', 'size:3'],
            'as_draft'           => ['sometimes', 'boolean'],
            'max_order_quantity' => ['nullable', 'numeric', 'gte:min_order_quantity'],
            'total_quantity'     => ['required', 'numeric', 'min:1', 'gte:min_order_quantity'],
        ]);
    }

    /**
     * Editing a listing that already exists.
     *
     * Every field is `sometimes`: a form that posts the three fields a seller
     * changed must not be read as "blank the other fifteen".
     *
     * What is deliberately absent is the listing's subject: the warehouse, the
     * variant, the category, the currency. Those are not fields of an edit because
     * changing one changes what is being sold — a price on different goods is a
     * different listing, and the row already has buyers' reservations against it.
     * Anything sent for them is ignored by `validated()`, not silently written.
     *
     * The quantities carry no cross-field rule for the reason given on `store()`:
     * an edit may change one of the three, so the comparison belongs to the code
     * that can see the other two on the row.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function update(): array
    {
        return array_merge(self::fields(edit: true), [
            'max_order_quantity' => ['sometimes', 'nullable', 'numeric', 'min:1'],
            'total_quantity'     => ['sometimes', 'required', 'numeric', 'min:1'],
        ]);
    }

    /**
     * Searching the market near a point.
     *
     * Read rules, so nothing here is `required` but the position: a filter that
     * was not sent must not be invented, and one that arrived empty is dropped by
     * `GeoDiscoveryService::search()` rather than turned into `WHERE ''`.
     *
     * The radius ceiling is a validation rule only up to the row's own bound: the
     * deployment's limit (`geo.max_radius_km`) is applied by
     * `GeoDiscoveryService::resolveRadius()`, because a cap read from settings
     * cannot be written into a compiled rule list.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function nearby(): array
    {
        return [
            'latitude'           => ['required', 'numeric', 'between:-90,90'],
            'longitude'          => ['required', 'numeric', 'between:-180,180'],
            'radius_km'          => ['nullable', 'numeric', 'min:1', 'max:500'],
            'category_id'        => ['nullable', 'integer', 'exists:categories,id'],
            'opportunity_reason' => ['nullable', 'string'],
            'min_price'          => ['nullable', 'numeric', 'min:0'],
            'max_price'          => ['nullable', 'numeric', 'min:0'],
            'min_quantity'       => ['nullable', 'numeric', 'min:0'],
            'max_moq'            => ['nullable', 'numeric', 'min:0'],
            'currency_code'      => ['nullable', 'string', 'size:3'],
            'delivery_terms'     => ['nullable', Rule::in(Offer::DELIVERY_TERMS)],
            'sort_by'            => ['nullable', 'in:distance,price,freshness'],
            'per_page'           => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    /**
     * The fields both writes take, in the one place their limits are stated.
     *
     * `$edit` is the difference between the two forms: an edit may mention a field
     * or leave it alone, which is `sometimes`; a creation has to say what the
     * listing is.
     *
     * Two fields are named twice rather than derived, because that is what they
     * actually are: `store` answers `null` for "the seller said nothing" while
     * `update` answers "not mentioned". A rule list that smoothed that over would
     * let an edit clear a listing's delivery terms by sending a field the form
     * never showed.
     *
     * @return array<string, array<int, mixed>>
     */
    private static function fields(bool $edit): array
    {
        $mention = fn (array $rules): array => $edit ? array_merge(['sometimes'], $rules) : $rules;

        return [
            'title_ar'                => $mention(['required', 'string', 'max:255']),
            'title_en'                => $mention(['nullable', 'string', 'max:255']),
            'description_ar'          => $mention(['nullable', 'string', 'max:2000']),
            'description_en'          => $mention(['nullable', 'string', 'max:2000']),
            'opportunity_reason'      => $mention(['required', Rule::in(Offer::OPPORTUNITY_REASONS)]),
            'opportunity_reason_notes' => $mention(['nullable', 'string', 'max:500']),
            'min_order_quantity'      => $mention(['required', 'numeric', 'min:1']),
            'unit'                    => $mention(['required', 'string', 'max:50']),
            'unit_price'              => $mention(['required', 'numeric', 'min:0.0001']),
            'production_date'         => $mention(['nullable', 'date']),
            'expiry_date'             => $mention(['nullable', 'date']),
            'storage_condition'       => $mention(['nullable', 'string', 'max:50']),
            'inspection_window_hours' => $mention(['nullable', 'integer', 'min:1', 'max:168']),
            'expires_at'              => $mention(['nullable', 'date', 'after:now']),
            'delivery_terms'          => $edit
                ? ['sometimes', Rule::in(Offer::DELIVERY_TERMS)]
                : ['nullable', Rule::in(Offer::DELIVERY_TERMS)],
            // Absent means "show", which is how every listing behaved before the
            // column existed; an explicit false is the only way to hide.
            'show_seller_identity'    => $edit ? ['sometimes', 'boolean'] : ['nullable', 'boolean'],
        ];
    }
}
