<?php

namespace App\Platform\Controllers;

use App\Core\Http\ApiResponse;
use App\Core\Http\Controllers\Controller;
use App\Platform\Services\AuditTrailQueries;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Who may read the audit trail, and what refusal they get when they may not.
 *
 * This door used to be a closure inside the route file, which meant the answer to
 * "can this person see events outside their own business?" lived in the routing
 * table rather than in the module that owns the record. The scoping rule itself is
 * `AuditTrailQueries`'; what is decided here is only whether the caller is platform
 * staff, which filters the caller is allowed to pass, and which refusal a request
 * that resolves to no business receives.
 *
 * Staff are not a filter's worth of latitude: `SuperAdmin` is read from the account,
 * not from a query parameter, because a client that could ask to be treated as
 * platform staff would be telling the server whose data to hand it.
 */
class AuditLogController extends Controller
{
    public function __construct(
        private readonly AuditTrailQueries $trail,
    ) {}

    /**
     * One page of recorded decisions.
     *
     * `permission:audit.view` is enforced at the route; what is checked here is the
     * narrower question of whose rows the caller may read within that view.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['nullable', 'string', 'max:255'],
            'auditable_type' => ['nullable', 'string', 'max:255'],
            'user_id' => ['nullable', 'integer'],
            'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $isPlatform = $request->user()->hasRole('SuperAdmin');
        $page = $this->trail->pageFor($validated, $isPlatform);

        // A business-scoped caller with no resolved tenant is refused rather than
        // shown an empty list: "no events" and "you have no business in context"
        // are different facts, and the operator has to know which one to fix.
        if ($page === null) {
            return ApiResponse::error(
                'لا سياق منشأة لهذا الطلب: اختر منشأة أو ادخل بصفة إشرافية على المنصة.',
                'NO_ORGANIZATION_CONTEXT',
                Response::HTTP_FORBIDDEN
            );
        }

        return ApiResponse::paginated($page, 'سجل الأحداث');
    }
}
