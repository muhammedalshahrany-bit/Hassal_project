<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $status = Password::sendResetLink(['email' => $request->input('email')]);

        if ($status !== Password::RESET_LINK_SENT) {
            return ApiResponse::error(
                'تعذر إرسال رابط استعادة كلمة المرور لهذا البريد',
                'PASSWORD_RESET_FAILED',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        return ApiResponse::success(null, 'تم إرسال رابط استعادة كلمة المرور إلى بريدك الإلكتروني');
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $status = Password::reset($validated, function (User $user, string $password) {
            $user->forceFill(['password' => Hash::make($password)])->save();
            $user->tokens()->delete();
        });

        if ($status !== Password::PASSWORD_RESET) {
            return ApiResponse::error(
                'رابط استعادة كلمة المرور غير صالح أو منتهي',
                'PASSWORD_RESET_INVALID',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        return ApiResponse::success(null, 'تم تغيير كلمة المرور بنجاح');
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        $validated = $request->validated();

        if (empty($validated['email']) || empty($validated['password'])) {
            return ApiResponse::error(
                'Email and password are required to create an account',
                'INVALID_REGISTRATION_DATA',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $validated['name'] = $validated['name'] ?? 'New User';

        $result = DB::transaction(function () use ($validated) {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
                'national_id' => $validated['national_id'] ?? null,
                'password' => Hash::make($validated['password']),
                'status' => 'active',
            ]);

            // Assign default role
            $user->assignRole('BuyerStaff');

            // If organization details are provided, create organization & set user as owner
            $organization = null;
            if (!empty($validated['organization_name'])) {
                $organization = Organization::create([
                    'name_ar' => $validated['organization_name'],
                    'name_en' => $validated['organization_name'],
                    'type' => $validated['organization_type'] ?? 'buyer',
                    'commercial_registration_number' => $validated['commercial_registration_number'] ?? ('PENDING-' . \Illuminate\Support\Str::uuid()),
                    'vat_number' => $validated['vat_number'] ?? null,
                    'status' => 'pending_kyc',
                ]);

                OrganizationMembership::create([
                    'organization_id' => $organization->id,
                    'user_id' => $user->id,
                    'role' => 'owner',
                    'is_active' => true,
                    'joined_at' => now(),
                ]);

                // Also assign buyer/seller admin role based on type
                if ($organization->type === 'seller') {
                    $user->syncRoles(['SellerAdmin']);
                } else {
                    $user->syncRoles(['BuyerAdmin']);
                }
            }

            $token = $user->createToken('auth_token')->plainTextToken;

                return [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'phone' => $user->phone,
                        'status' => $user->status,
                        'roles' => $user->getRoleNames(),
                        'permissions' => $user->getAllPermissions()->pluck('name'),
                    ],
                'token' => $token,
                'organization' => $organization,
            ];
        });

        return ApiResponse::success(
            $result,
            'User registered successfully',
            Response::HTTP_CREATED
        );
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $login = $validated['login'];

        $user = User::where('email', $login)
            ->orWhere('phone', $login)
            ->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            return ApiResponse::error(
                'Invalid credentials provided',
                'INVALID_CREDENTIALS',
                Response::HTTP_UNAUTHORIZED
            );
        }

        if ($user->status !== 'active') {
            return ApiResponse::error(
                'Account is currently ' . $user->status,
                'ACCOUNT_INACTIVE',
                Response::HTTP_FORBIDDEN
            );
        }

        $deviceName = $validated['device_name'] ?? 'api-client';
        $token = $user->createToken($deviceName)->plainTextToken;

        $memberships = $user->memberships()->where('is_active', true)->with('organization')->get();
        $defaultOrg = $memberships->first()?->organization;

        return ApiResponse::success([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'status' => $user->status,
                'roles' => $user->getRoleNames(),
                'permissions' => $user->getAllPermissions()->pluck('name'),
            ],
            'active_organization' => $defaultOrg,
            'organizations' => $memberships->map(fn($m) => [
                'id' => $m->organization->id,
                'name_ar' => $m->organization->name_ar,
                'name_en' => $m->organization->name_en,
                'type' => $m->organization->type,
                'role' => $m->role,
                'status' => $m->organization->status,
            ]),
            'token' => $token,
        ], 'Login successful');
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $currentOrg = TenantContext::get();
        $currentMembership = TenantContext::membership();

        $memberships = $user->memberships()->where('is_active', true)->with('organization')->get();

        return ApiResponse::success([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'national_id' => $user->national_id,
                'status' => $user->status,
                'roles' => $user->getRoleNames(),
                'permissions' => $user->getAllPermissions()->pluck('name'),
            ],
            'current_organization' => $currentOrg ? [
                'id' => $currentOrg->id,
                'name_ar' => $currentOrg->name_ar,
                'name_en' => $currentOrg->name_en,
                'type' => $currentOrg->type,
                'status' => $currentOrg->status,
                'cr_number' => $currentOrg->commercial_registration_number,
                'vat_number' => $currentOrg->vat_number,
                'credit_limit' => $currentOrg->credit_limit,
                'rating' => $currentOrg->rating,
                'membership_role' => $currentMembership?->role,
            ] : null,
            'organizations' => $memberships->map(fn($m) => [
                'id' => $m->organization->id,
                'name_ar' => $m->organization->name_ar,
                'name_en' => $m->organization->name_en,
                'type' => $m->organization->type,
                'role' => $m->role,
                'status' => $m->organization->status,
            ]),
        ], 'Profile retrieved successfully');
    }

    public function switchOrganization(Request $request): JsonResponse
    {
        $request->validate([
            'organization_id' => ['required', 'exists:organizations,id'],
        ]);

        $user = $request->user();
        $orgId = $request->input('organization_id');

        $membership = $user->memberships()
            ->where('organization_id', $orgId)
            ->where('is_active', true)
            ->with('organization')
            ->first();

        if (!$membership && !$user->hasRole('SuperAdmin')) {
            return ApiResponse::error(
                'You are not an active member of this organization',
                'ORGANIZATION_NOT_MEMBER',
                Response::HTTP_FORBIDDEN
            );
        }

        $org = $membership ? $membership->organization : Organization::findOrFail($orgId);

        TenantContext::set($org, $membership);

        return ApiResponse::success([
            'organization' => [
                'id' => $org->id,
                'name_ar' => $org->name_ar,
                'name_en' => $org->name_en,
                'type' => $org->type,
                'status' => $org->status,
                'role' => $membership?->role ?? 'superadmin',
            ],
        ], 'Switched active organization context successfully');
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return ApiResponse::success(null, 'Logged out successfully');
    }
}
