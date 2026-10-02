<?php

namespace App\Platform\Services;

use App\Core\Models\AuditLog;
use App\Core\Tenant\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Whose events a caller may read, and in what shape.
 *
 * The audit trail is the one surface in this module that answers a different
 * question depending on who asks it. A platform operator reads the whole record
 * because a decision they have to explain may have been taken in any business;
 * everyone else is scoped by the server to the organization the request resolves
 * to, and an unscoped request from them is refused rather than answered with the
 * whole table. That rule is not a filter the caller may lift, which is why it is
 * written here instead of at the route.
 *
 * Only the read half of the trail lives in this class. Rows are written by the
 * audit middleware and by the services that record a decision, and nothing here
 * changes a stored value — a trail a reader can edit is not evidence.
 */
final class AuditTrailQueries
{
    /**
     * One page of the trail, scoped to what the caller is standing for.
     *
     * `per_page` is capped rather than trusted: the trail holds every old and new
     * value of every write, so a page size from the client is how an ordinary
     * request ends up loading the whole table.
     *
     * @param  array<string, mixed>  $filters  action, auditable_type, user_id, organization_id, page, per_page
     *
     * @return LengthAwarePaginator<int, AuditLog>|null  null when the caller has no
     *         organization to be scoped to and is not platform staff — the door
     *         answers that, because only the door knows which refusal to name.
     */
    public function pageFor(array $filters, bool $isPlatform): ?LengthAwarePaginator
    {
        $organizationId = TenantContext::id();

        if ($isPlatform) {
            $query = AuditLog::with(['user:id,name,email', 'organization:id,name_ar,name_en']);

            // An operator may narrow the view to one business; leaving the filter
            // empty is the whole platform, not a guess at one organization.
            if (filled($filters['organization_id'] ?? null)) {
                $query->where('organization_id', (int) $filters['organization_id']);
            }
        } elseif ($organizationId !== null) {
            $query = AuditLog::with(['user:id,name,email'])->where('organization_id', $organizationId);
        } else {
            return null;
        }

        // Matched whole, as the values are stored. Actions arrive either as
        // "METHOD path" from the middleware or as a constant from the service that
        // logged a decision, so a partial match would mix a route hit with the
        // verdict recorded beside it.
        if (filled($filters['action'] ?? null)) {
            $query->where('action', $filters['action']);
        }

        if (filled($filters['auditable_type'] ?? null)) {
            $query->where('auditable_type', $filters['auditable_type']);
        }

        if (filled($filters['user_id'] ?? null)) {
            $query->where('user_id', (int) $filters['user_id']);
        }

        return $query
            ->orderBy('id', 'desc')
            ->paginate(min((int) ($filters['per_page'] ?? 20), 100));
    }
}
