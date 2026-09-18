<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class UserManagementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = User::with(['roles', 'organizations']);

        if ($request->filled('search')) {
            $term = '%' . $request->query('search') . '%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                  ->orWhere('email', 'like', $term)
                  ->orWhere('phone', 'like', $term);
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $users = $query->orderBy('id', 'desc')->paginate($request->query('per_page', 20));
        return ApiResponse::paginated($users, 'Users retrieved successfully');
    }

    public function show(int $id): JsonResponse
    {
        $user = User::with(['roles.permissions', 'organizations', 'memberships.organization'])->findOrFail($id);
        return ApiResponse::success($user, 'User details retrieved');
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        $validated = $request->validate([
            'status' => 'required|in:ACTIVE,SUSPENDED,INACTIVE',
            'reason' => 'required|string|max:255',
        ]);

        $previousStatus = $user->status;
        $user->update(['status' => $validated['status']]);

        // Audit Trail
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'USER_STATUS_UPDATE',
            'entity_type' => User::class,
            'entity_id' => $user->id,
            'old_values' => ['status' => $previousStatus],
            'new_values' => ['status' => $validated['status'], 'reason' => $validated['reason']],
            'ip_address' => $request->ip(),
        ]);

        return ApiResponse::success($user, "User status updated to {$validated['status']}");
    }

    public function assignRole(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        $validated = $request->validate([
            'role_name' => 'required|exists:roles,name',
        ]);

        $user->assignRole($validated['role_name']);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'USER_ROLE_ASSIGNED',
            'entity_type' => User::class,
            'entity_id' => $user->id,
            'new_values' => ['role' => $validated['role_name']],
            'ip_address' => $request->ip(),
        ]);

        return ApiResponse::success($user->load('roles'), "Role {$validated['role_name']} assigned to user");
    }

    public function revokeRole(Request $request, int $id, string $roleName): JsonResponse
    {
        $user = User::findOrFail($id);
        $user->removeRole($roleName);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'USER_ROLE_REVOKED',
            'entity_type' => User::class,
            'entity_id' => $user->id,
            'old_values' => ['role' => $roleName],
            'ip_address' => $request->ip(),
        ]);

        return ApiResponse::success($user->load('roles'), "Role {$roleName} revoked from user");
    }
}
