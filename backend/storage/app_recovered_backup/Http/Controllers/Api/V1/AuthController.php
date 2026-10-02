<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Exceptions\GoogleIdTokenRejectedException;
use App\Http\Requests\GoogleSignInRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Services\Auth\DeviceRegistry;
use App\Http\Responses\ApiResponse;
use App\Models\ExternalIdentity;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\Auth\GoogleIdTokenVerifier;
use App\Services\SystemSettingsService;
use App\Services\TenantContext;
use App\Support\AccountRequirements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\NewAccessToken;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    public function __construct(
        private readonly GoogleIdTokenVerifier $googleTokens,
        private readonly SystemSettingsService $settings,
        private readonly AccountRequirements $accountRequirements,
        private readonly DeviceRegistry $devices,
    ) {
    }

    /**
     * Sign in with a Google assertion.
     *
     * The browser holds a signed ID token from Google and hands it over; this
     * endpoint decides what to do with it. That order is the whole design: the
     * client cannot say who a person is, only present what Google said, and the
     * signature and audience are checked here before any claim is read.
     *
     * Three outcomes, and they are different decisions:
     *
     *   * an identity row already names this subject — the same person, again;
     *   * no row, but a verified address matches an account that holds no other
     *     Google identity — the address is linked. This is the same proof the
     *     password-reset flow already accepts (control of the mailbox), so it
     *     grants no authority the platform did not already concede. An account
     *     already bound to a *different* subject is refused rather than
     *     re-bound: two people cannot own one account because a mailbox changed
     *     hands at the provider;
     *   * nothing matches — an account is created if the operator left
     *     self-provisioning on, and refused if they turned it off to keep the
     *     platform on an invitation list.
     *
     * A new account is given no password, no role and no organization. Its
     * `password` column stays NULL, which is the honest statement that this
     * person does not authenticate here; `login()` reads that and says so. What
     * it does get is the answer `requires_profile_completion`, so the screen in
     * front of them asks for what this deployment still needs — and nothing it
     * has not asked for.
     */
    public function google(GoogleSignInRequest $request): JsonResponse
    {
        // The two refusals are separate because an operator fixes them in
        // different places: one has switched Google off, the other switched it
        // on and has not pasted in the client ID yet. Answering the second with
        // "disabled" sends them looking for a switch that is already on.
        if (! $this->googleSignInEnabled()) {
            return ApiResponse::error(
                'تسجيل الدخول عبر جوجل غير مُفعَّل في هذا النشر',
                'GOOGLE_SIGNIN_DISABLED',
                Response::HTTP_FORBIDDEN
            );
        }

        if (! $this->googleClientConfigured()) {
            return ApiResponse::error(
                'تسجيل جوجل مُفعَّل ولكن معرّف العميل غير مضبوط؛ اضبطه في إعدادات المنصة.',
                'GOOGLE_NOT_CONFIGURED',
                Response::HTTP_FORBIDDEN
            );
        }

        $validated = $request->validated();

        try {
            $assertion = $this->googleTokens->verify($validated['credential'], $validated['nonce']);
        } catch (GoogleIdTokenRejectedException $e) {
            // A rejected token is not a person's mistake to read about. The
            // message they see is one sentence; the machine code — which of the
            // checks failed — travels in `error.details.reason` for the log and
            // for the client that knows whether to retry.
            Log::warning('Google sign-in refused', ['reason' => $e->reason()]);

            return ApiResponse::error(
                $e->retryable()
                    ? 'تعذّر التحقق من حساب جوجل الآن؛ أعد المحاولة.'
                    : 'لم يتم التحقق من حساب جوجل.',
                'GOOGLE_TOKEN_REJECTED',
                $e->reason() === GoogleIdTokenRejectedException::REASON_PROVIDER_UNAVAILABLE
                    ? Response::HTTP_SERVICE_UNAVAILABLE
                    : Response::HTTP_UNAUTHORIZED,
                ['reason' => $e->reason(), 'retryable' => $e->retryable()]
            );
        }

        $result = DB::transaction(function () use ($assertion) {
            $identity = ExternalIdentity::query()
                ->where('provider', ExternalIdentity::PROVIDER_GOOGLE)
                ->where('provider_user_id', $assertion['subject'])
                ->first();

            if ($identity) {
                // Checked before anything is written, not after. A suspended
                // account that gets its identity row refreshed has been treated
                // as though it signed in, and the refusal on the way out leaves
                // the record of that behind. Access is this platform's decision,
                // and it is made before its own books are changed.
                $owner = $identity->user;

                if ($owner === null || $owner->status !== 'active') {
                    return ['refusal' => 'ACCOUNT_INACTIVE', 'user' => null, 'identity' => null, 'account_created' => false];
                }

                $identity->forceFill([
                    'email' => $assertion['email'],
                    'email_verified_at' => now(),
                    'last_verified_at' => now(),
                ])->save();

                return ['user' => $owner, 'identity' => $identity, 'account_created' => false];
            }

            $user = User::where('email', $assertion['email'])->first();
            $accountCreated = false;

            if ($user) {
                $existing = $user->externalIdentities()
                    ->where('provider', ExternalIdentity::PROVIDER_GOOGLE)
                    ->first();

                if ($existing) {
                    // The address is here and belongs to someone else's Google
                    // account. Merging would hand that person's business over.
                    return ['conflict' => 'GOOGLE_ACCOUNT_ALREADY_LINKED', 'user' => null, 'identity' => null, 'account_created' => false];
                }

                if ($user->status !== 'active') {
                    return ['refusal' => 'ACCOUNT_INACTIVE', 'user' => null, 'identity' => null, 'account_created' => false];
                }
            } else {
                if (! filter_var(
                    (string) $this->settings->get('auth.google_auto_register', '1'),
                    FILTER_VALIDATE_BOOLEAN
                )) {
                    return ['conflict' => 'GOOGLE_ACCOUNT_NOT_PROVISIONED', 'user' => null, 'identity' => null, 'account_created' => false];
                }

                $accountCreated = true;

                $user = User::create([
                    'name' => $assertion['name'] ?: Str::before($assertion['email'], '@'),
                    'email' => $assertion['email'],
                    // No password is invented for this row. A random string
                    // would sit in the column looking like a credential while
                    // being one nobody holds; NULL is the truth, and `login()`
                    // now reads it as the truth.
                    'password' => null,
                    'status' => 'active',
                    // Google said it verified this address, and its word about
                    // its own mailbox is the thing this column means.
                    'email_verified_at' => now(),
                ]);
            }

            $identity = ExternalIdentity::create([
                'user_id' => $user->id,
                'provider' => ExternalIdentity::PROVIDER_GOOGLE,
                'provider_user_id' => $assertion['subject'],
                'email' => $assertion['email'],
                'email_verified_at' => now(),
                'linked_at' => now(),
                'last_verified_at' => now(),
            ]);

            return ['user' => $user, 'identity' => $identity, 'account_created' => $accountCreated];
        });

        if (($result['refusal'] ?? null) === 'ACCOUNT_INACTIVE') {
            return ApiResponse::error(
                'هذا الحساب غير نشط حاليًا؛ تواصل مع إدارة المنصة.',
                'ACCOUNT_INACTIVE',
                Response::HTTP_FORBIDDEN
            );
        }

        if (isset($result['conflict'])) {
            return ApiResponse::error(
                $result['conflict'] === 'GOOGLE_ACCOUNT_ALREADY_LINKED'
                    ? 'هذا الحساب مرتبط مسبقًا بحساب جوجل آخر؛ لا يمكن ربطه بآخر.'
                    : 'لا حساب لهذا البريد في المنصة؛ سجّل أولًا ثم اربط حساب جوجل.',
                $result['conflict'],
                Response::HTTP_FORBIDDEN
            );
        }

        // A person who did not exist a moment ago is answered differently from
        // one who walked back in — including one whose account only gained a
        // Google identity just now. 201 says "this request opened an account",
        // which is the fact the client needs when it decides between the setup
        // flow and the sign-in one.
        return ApiResponse::success(
            $this->sessionPayload($result['user'], $request->input('device_name', 'google'), $request->device()),
            'Login with Google successful',
            $result['account_created'] ? Response::HTTP_CREATED : Response::HTTP_OK
        );
    }

    public function googleSignInEnabled(): bool
    {
        return filter_var(
            (string) $this->settings->get('auth.google_enabled', '0'),
            FILTER_VALIDATE_BOOLEAN
        );
    }

    /**
     * Whether this deployment has told Google who it is.
     *
     * A switch with no client ID behind it is not half-on. The button cannot be
     * drawn without the ID and no token could be addressed to it, so the door
     * stays shut from both ends. The value itself is a public setting — Google's
     * own documentation calls a client ID a public identifier, and the browser
     * has to hold it to ask for a token — which is why it lives in
     * `system_settings` where an administrator can change it rather than in a
     * file that would need a deploy, and why no client *secret* exists here at
     * all: an ID token from the browser is verified against Google's published
     * keys, not against a credential.
     */
    public function googleClientConfigured(): bool
    {
        return trim((string) $this->settings->get('auth.google_client_id', '')) !== '';
    }

    /**
     * The body every sign-in answers with, built in one place.
     *
     * Login, Google and (once it stops inventing companies) registration all
     * hand the client the same thing: a token, the account, the businesses it
     * belongs to, and what is still outstanding. Three copies of that shape is
     * how one of them drifts — and the outstanding-fields list is exactly the
     * part a screen needs to be right about, because it is the difference
     * between "welcome" and "we cannot let you trade yet".
     *
     * `$device` is the optional third thing a sign-in carries: the handset or
     * browser it came from, recorded against the token this payload returns.
     * Null keeps the pre-device behaviour — a session that works and is not
     * listed, because nothing named a device to list.
     *
     * @param  array{key: string, name: string, platform: string, biometric_unlock: bool}|null  $device
     * @return array<string, mixed>
     */
    private function sessionPayload(User $user, string $deviceName, ?array $device = null): array
    {
        $memberships = $user->memberships()->where('is_active', true)->with('organization')->get();
        $defaultMembership = $memberships->first();
        $defaultOrg = $defaultMembership?->organization;

        $token = $this->issueToken($user, $device['name'] ?? $deviceName);
        // The row is written after the token exists because it points at it.
        // Nothing in the payload depends on the result: a client that sent no
        // device simply is not listed, exactly as before this field existed.
        $this->devices->record($user, $device, $token->accessToken->id);

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'status' => $user->status,
                'roles' => $user->getRoleNames(),
                // The capabilities of the business this session lands in, because
                // that is what the membership row in front of them says. Answering
                // with the global rows alone would tell the owner of a company who
                // holds no platform role that they can do nothing, and the screen
                // would then have to guess — from a client, about a server.
                'permissions' => $this->withOrganizationContext(
                    $defaultMembership,
                    fn () => $user->getAllPermissions()->pluck('name')
                ),
                // Signed-in from the first frame: the switches behind this map
                // decide whether the acting business's name is printed at all,
                // and a client that only learned the answer on the next call
                // would have printed it for a moment to the person who turned
                // it off.
                'preferences' => $user->preferences(),
            ],
            'active_organization' => $defaultOrg,
            'organizations' => $memberships->map(fn ($m) => [
                'id' => $m->organization->id,
                'name_ar' => $m->organization->name_ar,
                'name_en' => $m->organization->name_en,
                'type' => $m->organization->type,
                'role' => $m->role,
                'status' => $m->organization->status,
            ]),
            'token' => $token->plainTextToken,
            'profile_completion' => $this->profileCompletion($user, $memberships),
        ];
    }

    /**
     * What the account still owes this deployment, said once and read everywhere.
     *
     * A sign-in response and `/auth/me` carry the same block because a client
     * should never have to keep its own copy of the rule — the usual way a form
     * ends up asking for something the server has stopped requiring, or staying
     * quiet while an action is refused.
     *
     * `has_password` is in here for the same reason: an account opened from a
     * provider's assertion has no local password, and offering it a "change
     * password" form would be offering it a door it cannot open.
     *
     * @param  \Illuminate\Support\Collection<int, OrganizationMembership>  $memberships
     * @return array<string, mixed>
     */
    private function profileCompletion(User $user, $memberships): array
    {
        $missing = $this->accountRequirements->missingFields($user);

        return [
            'required' => $missing !== [] || $memberships->isEmpty(),
            'missing_fields' => $missing,
            'has_organization' => $memberships->isNotEmpty(),
            'has_password' => $user->password !== null,
        ];
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $status = Password::sendResetLink(['email' => $request->input('email')]);

        if ($status !== Password::RESET_LINK_SENT) {
            // The reason stays on the server. Telling the caller "no such
            // account" would let anybody with a mailing list confirm which of
            // those addresses trade on this platform.
            Log::info('Password reset link not sent', [
                'status' => $status,
                'email_domain' => Str::after((string) $request->input('email'), '@'),
            ]);
        }

        return ApiResponse::success(null, 'إن كان هذا البريد مسجلاً لدينا فقد أُرسل إليه رابط استعادة كلمة المرور');
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

    /**
     * Create a person.
     *
     * That is all this does, and the narrowing is the point. The endpoint used
     * to take an optional `organization_name` and, if it was filled, create a
     * business with the caller as its owner — a second, weaker door to the same
     * room as `POST /organizations`. The second door checked no collision on the
     * commercial registration number, no `legal.*` requirement an administrator
     * switched on, and no limit on how many businesses one account may open; it
     * asked for less, so it was the door everyone came through, and every rule
     * the other door held was a rule that could be signed around at the moment
     * a business is born.
     *
     * A business is now created in one place, by one request, against one set of
     * rules (`StoreOrganizationRequest` and `OrganizationController::store`).
     * What this endpoint hands back is a session and the list of what that
     * session still lacks — the fields this deployment requires, and the fact
     * that it holds no organization yet — so the screen after sign-up asks for
     * exactly that and nothing it invented.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'national_id' => $validated['national_id'] ?? null,
            'password' => $validated['password'],
            'status' => 'active',
        ]);

        // No role is attached to a new account. The `assignRole('BuyerStaff')`
        // that used to sit here granted nothing even when it did: it stamped a
        // company standing onto the account, which then followed the person
        // into businesses they had no relation to. Standing comes from a
        // membership row, so an account that has signed up and not yet joined
        // or created a business holds only the declared person capabilities —
        // liking a listing and asking a public question in its own name — and
        // every trading door still asks it to name a company first.

        return ApiResponse::success(
            $this->sessionPayload($user, 'api-client', $request->device()),
            'User registered successfully',
            Response::HTTP_CREATED
        );
    }

    /**
     * Issue a session token that expires.
     *
     * Sanctum's createToken() takes an expiry as its third argument, and
     * config/hassal.php declares `auth.token_ttl_minutes` — but the calls here
     * passed neither, so every token issued by this platform was immortal and
     * the setting gated nothing. A configuration value nothing reads is not a
     * control, and a bearer token that never dies is a credential that survives
     * a password change, a revoked device and a departed employee.
     *
     * The access token is handed back whole rather than as its plain-text string,
     * because the device that just signed in has to be pointed at the credential
     * it now holds: a list of sessions with no way to name the token inside each
     * one is a list you can look at and not act on. Sanctum's answer carries
     * both halves — the row and the one string that will ever be shown.
     */
    private function issueToken(User $user, string $deviceName): NewAccessToken
    {
        $minutes = max(1, (int) config('hassal.auth.token_ttl_minutes', 720));

        return $user->createToken($deviceName, ['*'], now()->addMinutes($minutes));
    }

    /**
     * Answer for one organization, then put the context back.
     *
     * `User::hasPermissionTo()` and `getAllPermissions()` consult
     * `TenantContext`, which is a per-request holder normally filled by the
     * `tenant` middleware. Login and registration are public routes with no such
     * middleware, so the membership being handed to the client is set here for the
     * length of one callback and restored after — including the "there was no
     * context before" case, which must not be left pointing at a company.
     */
    private function withOrganizationContext(?OrganizationMembership $membership, callable $callback): mixed
    {
        if (!$membership) {
            return $callback();
        }

        $previousOrganization = TenantContext::get();
        $previousMembership = TenantContext::membership();

        TenantContext::set($membership->organization ?? $membership->organization()->first(), $membership);

        try {
            return $callback();
        } finally {
            if ($previousOrganization) {
                TenantContext::set($previousOrganization, $previousMembership);
            } else {
                TenantContext::clear();
            }
        }
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $login = $validated['login'];

        $user = User::where('email', $login)
            ->orWhere('phone', $login)
            ->first();

        // `password === null` is not a missing value to be forgiving about: it
        // means this account never had a local password, because it was opened
        // from a provider's assertion. Hash::check() on null is a fatal, and a
        // platform that crashed on the question would answer it — an error page
        // confirms an account exists — so the case is refused with the same
        // words a wrong password gets.
        if (! $user || $user->password === null || ! Hash::check($validated['password'], $user->password)) {
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

        return ApiResponse::success(
            $this->sessionPayload($user, $validated['device_name'] ?? 'api-client', $request->device()),
            'Login successful'
        );
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
                // Read on every client start-up, so it travels with the answer
                // that already carries the roles: a second round trip to learn
                // whether to hide the business name is a frame where the name
                // is shown to the person who asked for it never to appear.
                'preferences' => $user->preferences(),
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
            // Reported, not inferred by the client from which inputs happen to
            // be empty. A person who signed in through Google has no local
            // password and, until they say otherwise, no business; the screens
            // that need to know the difference — "set a password" versus
            // "change it", "add your organization" versus "you have three" —
            // ask here rather than keep a second copy of the rule.
            'profile_completion' => $this->profileCompletion($user, $memberships),
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

    /**
     * The account's own human details: name, phone, national id.
     *
     * Scope is deliberate. This route edits one row — the caller's own `users`
     * record — and nothing else: not the organization (that is
     * `PUT /organizations/{id}` behind `organizations.manage`), not roles
     * (`users.manage`, platform staff only). A profile screen that could rename
     * a company or grant itself a permission would be the shortest privilege
     * escalation in the product.
     *
     * `email` is not editable here. Controlling a mailbox is what the
     * password-reset flow proves, and moving an account's login identity by
     * typing into a form would hand the next owner the resets of whoever owns
     * the address. It stays a separate, verified operation.
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $rules = [
            'name'        => ['sometimes', 'required', 'string', 'max:255'],
            // A phone someone else verifies with is not a field to overwrite.
            // The rule is the answer the form shows, and the column's unique
            // index is the backstop under it — this endpoint holds no second
            // copy of that decision.
            'phone'       => ['sometimes', 'nullable', 'string', 'max:30', Rule::unique('users', 'phone')->ignore($user->id)],
            'national_id' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];

        // What this deployment demands is *not* enforced here, and the reason is
        // a lockout this endpoint would otherwise create: an account that is
        // missing a demanded field would be refused on the request it uses to
        // supply it, on any request that touches any other field. "Fix your
        // name" would answer "give us a phone number first", and a person with
        // no phone to give would have no way to edit anything at all.
        //
        // The demand is therefore made where the risk is: `POST /organizations`
        // refuses an incomplete account (`ACCOUNT_PROFILE_INCOMPLETE`), and the
        // answer it returns names the fields — the same list `AccountRequirements`
        // gives `/auth/me`, so the form and the gate cannot disagree. Completing
        // the account is one PATCH to this endpoint with those fields in it,
        // which is also what the sign-in flow's completion step sends.
        $validated = $request->validate($rules);

        $user->update($validated);

        $after = $this->accountRequirements->missingFields($user);

        return ApiResponse::success([
            'id'          => $user->id,
            'name'        => $user->name,
            'email'       => $user->email,
            'phone'       => $user->phone,
            'national_id' => $user->national_id,
            // Reported back so the screen can drop a field from its checklist
            // the moment the server has stopped asking for it.
            'profile_completion' => [
                'required' => $after !== [],
                'missing_fields' => $after,
            ],
        ], 'تم تحديث الملف الشخصي');
    }

    /**
     * Change the password, and close the doors the old one opened.
     *
     * Every other token of the account is revoked; the token making this call
     * survives so the user is not logged out by their own security action. A
     * password change that left stolen tokens alive would be a formality.
     *
     * An account with no local password — one opened from a Google assertion —
     * has no current password to name, and asking for one would lock it out of
     * the credential it does not hold. For that case alone the field is not
     * required, and the write becomes a first password rather than a change.
     * `Hash::check($x, null)` is a fatal in PHP 8, so the branch is load-bearing
     * for more than politeness.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();
        $hadNoPassword = $user->password === null;

        $validated = $request->validate([
            'current_password' => [$hadNoPassword ? 'nullable' : 'required', 'string'],
            'password'         => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (! $hadNoPassword && ! Hash::check($validated['current_password'], $user->password)) {
            // The same answer as a wrong login: whether this account exists is
            // not this endpoint's to confirm.
            return ApiResponse::error(
                'كلمة المرور الحالية غير صحيحة',
                'CURRENT_PASSWORD_INCORRECT',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $currentTokenId = $user->currentAccessToken()?->id;

        $user->forceFill(['password' => Hash::make($validated['password'])])->save();

        $user->tokens()
            ->when($currentTokenId, fn ($query) => $query->whereKeyNot($currentTokenId))
            ->delete();

        return ApiResponse::success(null, $hadNoPassword
            ? 'تم تعيين كلمة المرور لحسابك'
            : 'تم تغيير كلمة المرور بنجاح');
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return ApiResponse::success(null, 'Logged out successfully');
    }
}
