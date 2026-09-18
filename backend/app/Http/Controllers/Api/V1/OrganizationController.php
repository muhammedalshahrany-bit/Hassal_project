<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AddMemberRequest;
use App\Http\Requests\StoreOrganizationRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class OrganizationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasRole('SuperAdmin')) {
            $query = Organization::query();

            if ($request->has('type')) {
                $query->where('type', $request->query('type'));
            }

            if ($request->has('status')) {
                $query->where('status', $request->query('status'));
            }

            $organizations = $query->paginate($request->query('per_page', 15));
            return ApiResponse::paginated($organizations, 'All organizations retrieved');
        }

        $userOrganizations = $user->organizations()
            ->withPivot('role', 'is_active')
            ->paginate($request->query('per_page', 15));

        return ApiResponse::paginated($userOrganizations, 'User organizations retrieved');
    }

    public function store(StoreOrganizationRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = $request->user();

        $organization = DB::transaction(function () use ($validated, $user) {
            $org = Organization::create([
                'name_ar' => $validated['name_ar'],
                'name_en' => $validated['name_en'],
                'type' => $validated['type'],
                'commercial_registration_number' => $validated['commercial_registration_number'],
                'vat_number' => $validated['vat_number'],
                'status' => 'pending_kyc',
                'credit_limit' => $validated['credit_limit'] ?? 0.00,
                'metadata' => $validated['metadata'] ?? null,
            ]);

            OrganizationMembership::create([
                'organization_id' => $org->id,
                'user_id' => $user->id,
                'role' => 'owner',
                'is_active' => true,
                'joined_at' => now(),
            ]);

            return $org;
        });

        return ApiResponse::success(
            $organization->load('memberships.user'),
            'Organization registered successfully and awaiting verification',
            Response::HTTP_CREATED
        );
    }

    public function show(int|string $id): JsonResponse
    {
        $org = Organization::with(['memberships.user'])->findOrFail($id);

        return ApiResponse::success([
            'id' => $org->id,
            'name_ar' => $org->name_ar,
            'name_en' => $org->name_en,
            'type' => $org->type,
            'commercial_registration_number' => $org->commercial_registration_number,
            'vat_number' => $org->vat_number,
            'status' => $org->status,
            'credit_limit' => $org->credit_limit,
            'rating' => $org->rating,
            'kyc_verified_at' => $org->kyc_verified_at,
            'members_count' => $org->memberships()->where('is_active', true)->count(),
            'created_at' => $org->created_at,
        ], 'Organization retrieved successfully');
    }

    public function update(Request $request, int|string $id): JsonResponse
    {
        $org = Organization::findOrFail($id);
        $user = $request->user();

        // Check permission (owner, admin of this org or SuperAdmin)
        $role = $user->roleInOrganization($org->id);
        if (!$user->hasRole('SuperAdmin') && !in_array($role, ['owner', 'admin'])) {
            return ApiResponse::error(
                'Only organization owners or administrators can update organization profile',
                'FORBIDDEN',
                Response::HTTP_FORBIDDEN
            );
        }

        $validated = $request->validate([
            'name_ar' => ['sometimes', 'string', 'max:255'],
            'name_en' => ['sometimes', 'string', 'max:255'],
            'commercial_registration_number' => ['sometimes', 'string', 'max:50', 'unique:organizations,commercial_registration_number,' . $id],
            'vat_number' => ['sometimes', 'string', 'max:50', 'unique:organizations,vat_number,' . $id],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ]);

        $org->update($validated);

        return ApiResponse::success($org, 'Organization updated successfully');
    }

    public function members(int|string $id): JsonResponse
    {
        $org = Organization::findOrFail($id);
        $members = $org->memberships()
            ->where('is_active', true)
            ->with('user:id,name,email,phone,status')
            ->get();

        return ApiResponse::success($members, 'Organization members retrieved');
    }

    public function addMember(AddMemberRequest $request, int|string $id): JsonResponse
    {
        $org = Organization::findOrFail($id);
        $currentUser = $request->user();

        // Check if current user is owner or admin of this org
        $role = $currentUser->roleInOrganization($org->id);
        if (!$currentUser->hasRole('SuperAdmin') && !in_array($role, ['owner', 'admin'])) {
            return ApiResponse::error(
                'You do not have permission to invite or add members to this organization',
                'FORBIDDEN',
                Response::HTTP_FORBIDDEN
            );
        }

        $validated = $request->validated();
        $targetUserId = $validated['user_id'] ?? null;

        if (!$targetUserId && !empty($validated['email'])) {
            $user = User::where('email', $validated['email'])->first();
            if (!$user) {
                return ApiResponse::error('User with this email was not found', 'USER_NOT_FOUND', Response::HTTP_NOT_FOUND);
            }
            $targetUserId = $user->id;
        }

        $membership = OrganizationMembership::updateOrCreate(
            [
                'organization_id' => $org->id,
                'user_id' => $targetUserId,
            ],
            [
                'role' => $validated['role'],
                'is_active' => true,
                'joined_at' => now(),
            ]
        );

        return ApiResponse::success(
            $membership->load('user:id,name,email,phone'),
            'Member added to organization successfully',
            Response::HTTP_CREATED
        );
    }

    public function updateMember(Request $request, int|string $id, int|string $userId): JsonResponse
    {
        $org = Organization::findOrFail($id);
        $currentUser = $request->user();

        $role = $currentUser->roleInOrganization($org->id);
        if (!$currentUser->hasRole('SuperAdmin') && !in_array($role, ['owner', 'admin'])) {
            return ApiResponse::error(
                'You do not have permission to modify member roles',
                'FORBIDDEN',
                Response::HTTP_FORBIDDEN
            );
        }

        $validated = $request->validate([
            'role' => ['required', 'string', 'in:owner,admin,operator,finance,auditor'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $membership = OrganizationMembership::where('organization_id', $org->id)
            ->where('user_id', $userId)
            ->firstOrFail();

        $membership->update($validated);

        return ApiResponse::success($membership, 'Member role updated successfully');
    }

    public function removeMember(Request $request, int|string $id, int|string $userId): JsonResponse
    {
        $org = Organization::findOrFail($id);
        $currentUser = $request->user();

        $role = $currentUser->roleInOrganization($org->id);
        if (!$currentUser->hasRole('SuperAdmin') && !in_array($role, ['owner', 'admin'])) {
            return ApiResponse::error(
                'You do not have permission to remove members',
                'FORBIDDEN',
                Response::HTTP_FORBIDDEN
            );
        }

        // Prevent removing the last owner
        $membership = OrganizationMembership::where('organization_id', $org->id)
            ->where('user_id', $userId)
            ->firstOrFail();

        if ($membership->role === 'owner') {
            $ownersCount = OrganizationMembership::where('organization_id', $org->id)
                ->where('role', 'owner')
                ->where('is_active', true)
                ->count();

            if ($ownersCount <= 1) {
                return ApiResponse::error(
                    'Cannot remove the last remaining owner of the organization',
                    'CANNOT_REMOVE_LAST_OWNER',
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }
        }

        $membership->update(['is_active' => false]);

        return ApiResponse::success(null, 'Member deactivated and removed from active roster');
    }
}
