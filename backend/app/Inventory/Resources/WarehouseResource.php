<?php

namespace App\Inventory\Resources;

use App\Marketplace\Resources\OfferResource;

use App\Core\Tenant\TenantContext;
use App\Core\Support\Geo;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The one shape a warehouse is ever answered in.
 *
 * It exists because the same controller method serves two doors — the public
 * market's map board (`GET /marketplace/warehouses`, no token) and a business's
 * own list (`GET /warehouses`, behind `warehouses.view`) — and until now both
 * handed back a raw Eloquent row. That meant the public board published a
 * shed's `contact_phone` and `contact_name` to anyone who asked, and that a
 * client reading both lists had to guess which fields would be there.
 *
 * Two rules, the same ones `OfferResource` holds:
 *   1. **The keys are the same in every answer.** What changes between a guest
 *      and a member is the *value*, never the presence. A client that has to
 *      handle "this field is absent over there" is a client that will get it
 *      wrong in whichever direction it did not test.
 *   2. **Nothing is invented.** A warehouse that recorded no position has `null`
 *      coordinates and a `null` distance — not a zero, which would place every
 *      unlocated shed at the same point off the coast of Africa and rank it
 *      nearest to anyone standing there.
 *
 * `distance_km` is the whole reason this file was written now rather than left:
 * a map that draws pins without saying how far each one is asks the person
 * looking at it to do arithmetic on a projection they did not choose.
 */
class WarehouseResource extends JsonResource
{
    /**
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        // Whose shelf this is, and whether the caller is standing on it.
        //
        // Membership is what decides, not a role name: `WarehouseController`
        // already scopes a non-SuperAdmin's own list to the active tenant, so a
        // caller who can name the organization can see the row's organization.
        // An anonymous visitor and a signed-in buyer from another business both
        // read the public shape, and both are right not to see a phone number
        // that belongs to a warehouse manager they have no deal with.
        $showsContacts = $this->contactsShownTo($request);

        return [
            'id'              => $this->id,
            'organization_id' => $this->organization_id,
            'name'            => $this->name,
            'code'            => $this->code,
            'city'            => $this->city,
            'district'        => $this->district,
            'address_line'    => $this->address_line,

            // A coordinate is a location, and a location is what the map and the
            // distance both need — so it stays public even when the door it
            // belongs to does not.
            'latitude'        => $this->latitude === null ? null : (float) $this->latitude,
            'longitude'       => $this->longitude === null ? null : (float) $this->longitude,

            'contact_name'    => $showsContacts ? $this->contact_name : null,
            'contact_phone'   => $showsContacts ? $this->contact_phone : null,

            'is_active'       => (bool) $this->is_active,

            // Set by the controller when, and only when, the caller said where
            // they are standing. Without that the honest answer is `null`, and
            // `null` stays `null` rather than becoming `0.0`: a zero distance is
            // a claim that the goods are under the buyer's feet, and a map that
            // sorts on it puts every unlocated shed at the front of the list.
            'distance_km'     => Geo::present(
                $this->getAttribute('distance_km') === null
                    ? null
                    : (float) $this->getAttribute('distance_km')
            ),
        ];
    }

    /**
     * Whether this caller is given the person to call at this shed.
     *
     * The rule is the controller's own scoping, restated where the fields are
     * written rather than in two places that can drift: `WarehouseController`
     * answers the authenticated route with the active tenant's rows and the
     * public route with everybody's, so "the active tenant owns this row" is
     * exactly the set of callers who were already entitled to the row in full —
     * plus SuperAdmin, who sees every business's board by definition.
     *
     * An anonymous visitor and a signed-in buyer from another business both read
     * the public shape, and both are right not to be handed a warehouse
     * supervisor's mobile number. The name, the city and the coordinates stay:
     * a directory that hides where the goods are is not a map.
     */
    private function contactsShownTo($request): bool
    {
        $viewer = $request->user('sanctum');
        if (!$viewer) {
            return false;
        }

        if ($viewer->isPlatformOperator()) {
            return true;
        }

        $tenant = TenantContext::id();

        return $tenant !== null && (int) $tenant === (int) $this->organization_id;
    }
}
