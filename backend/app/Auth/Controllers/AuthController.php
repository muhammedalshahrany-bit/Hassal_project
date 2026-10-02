<?php

namespace App\Auth\Controllers;

use App\Core\Http\Controllers\Controller;
use App\Auth\Exceptions\GoogleIdTokenRejectedException;
use App\Auth\Requests\GoogleSignInRequest;
use App\Auth\Requests\LoginRequest;
use App\Auth\Requests\RegisterRequest;
use App\Auth\Services\GoogleSignInService;
use App\Auth\Services\PasswordCredentialService;
use App\Auth\Services\PasswordSignInService;
use App\Auth\Services\SessionService;
use App\Core\Http\ApiResponse;
use App\Organizations\Models\Organization;
use App\Identity\Models\User;
use App\Auth\Services\AccountRegistrationService;
use App\Auth\Services\GoogleIdTokenVerifier;
use App\Auth\Support\AccountRequirements;
use App\Auth\Support\GoogleSigninAvailability;
use App\Core\Tenant\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * The account's own doors: sign in, sign up, and the person's details.
 *
 * This layer reads the request and answers in the client's envelope. The
 * decisions it used to make itself — what a verified Google assertion means,
 * what a named password proves, what a session is composed of, and how a
 * credential is rewritten — now live in `GoogleSignInService`,
 * `PasswordSignInService`, `SessionService`, `AccountRegistrationService` and
 * `PasswordCredentialService`, so the same answer stands behind the password
 * door, the Google door and the sign-up door rather than three paraphrases of
 * each other. What remains here is the vocabulary the HTTP client is promised:
 * the refusal codes, the Arabic sentences, and the status each answer
 * travels with.
 *
 * A rule about the *deployment* rather than about the request — whether this
 * install may offer Google at all — is not this class's to hold either, because
 * a second door (`/bootstrap`, which decides whether to draw the button) has to
 * give the identical answer. That rule lives in `Support\GoogleSigninAvailability`
 * beside the settings it reads, so neither door can drift from the other.
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly GoogleIdTokenVerifier $googleTokens,
        private readonly GoogleSigninAvailability $googleAvailability,
        private readonly AccountRequirements $accountRequirements,
        private readonly GoogleSignInService $googleSignIn,
        private readonly SessionService $sessions,
        private readonly PasswordSignInService $passwordSignIn,
        private readonly PasswordCredentialService $credentials,
        private readonly AccountRegistrationService $registration,
    ) {
    }

    /**
     * Sign in with a Google assertion.
     *
     * The browser holds a signed ID token from Google and hands it over; this
     * endpoint decides what to do with it. That order is the whole design: the
     * client cannot say who a person is, only present what Google said, and the
     * signature and audience are checked here before any claim is read. The
     * account decision that follows — same person, linkable by verified
     * address, or a new account the operator's switches allow — is
     * `GoogleSignInService::resolve()`'s to make.
     */
    public function google(GoogleSignInRequest $request): JsonResponse
    {
        // The two refusals are separate because an operator fixes them in
        // different places: one has switched Google off, the other switched it
        // on and has not pasted in the client ID yet. Answering the second with
        // "disabled" sends them looking for a switch that is already on.
        // Which settings make the answer, and why the answer is not a constant,
        // is `GoogleSigninAvailability`'s to say — the same class the cold-start
        // payload consults before it decides whether to draw the button.
        if (! $this->googleAvailability->enabled()) {
            return ApiResponse::error(
                'تسجيل الدخول عبر جوجل غير مُفعَّل في هذا النشر',
                'GOOGLE_SIGNIN_DISABLED',
                Response::HTTP_FORBIDDEN
            );
        }

        if (! $this->googleAvailability->clientConfigured()) {
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

        $result = $this->googleSignIn->resolve($assertion);

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
            $this->sessions->issue($result['user'], $request->input('device_name', 'google'), $request->device()),
            'Login with Google successful',
            $result['account_created'] ? Response::HTTP_CREATED : Response::HTTP_OK
        );
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

        // The credential write and the sessions it ends are one act, and a reset
        // spares nothing: whoever held the password the link replaces is not the
        // person who asked for this one.
        $status = Password::reset(
            $validated,
            fn (User $user, string $password) => $this->credentials->setPassword($user, $password)
        );

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
        $user = $this->registration->create($request->validated());

        return ApiResponse::success(
            $this->sessions->issue($user, 'api-client', $request->device()),
            'User registered successfully',
            Response::HTTP_CREATED
        );
    }

    /**
     * Sign in with a password, by email or by phone.
     *
     * Whether the credentials open the account is `PasswordSignInService`'s
     * answer; this method only chooses the sentence each answer travels with.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $result = $this->passwordSignIn->attempt($validated['login'], $validated['password']);

        if (($result['refusal'] ?? null) === 'INVALID_CREDENTIALS') {
            return ApiResponse::error(
                'Invalid credentials provided',
                'INVALID_CREDENTIALS',
                Response::HTTP_UNAUTHORIZED
            );
        }

        if (($result['refusal'] ?? null) === 'ACCOUNT_INACTIVE') {
            return ApiResponse::error(
                'Account is currently ' . $result['status'],
                'ACCOUNT_INACTIVE',
                Response::HTTP_FORBIDDEN
            );
        }

        return ApiResponse::success(
            $this->sessions->issue($result['user'], $validated['device_name'] ?? 'api-client', $request->device()),
            'Login successful'
        );
    }

    /**
     * The account, the business it is acting for, and what is still outstanding.
     *
     * The person block and the list of businesses come from `SessionService`,
     * the same two methods a sign-in answer is built from: this is the same
     * account read a second time, and two copies of one shape is how a screen
     * comes to show a field that only appears after the page is reloaded.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $currentOrg = TenantContext::get();
        $currentMembership = TenantContext::membership();

        $memberships = $user->activeMemberships();

        return ApiResponse::success([
            'user' => $this->sessions->account($user),
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
            'organizations' => $this->sessions->organizationList($memberships),
            // Reported, not inferred by the client from which inputs happen to
            // be empty. A person who signed in through Google has no local
            // password and, until they say otherwise, no business; the screens
            // that need to know the difference — "set a password" versus
            // "change it", "add your organization" versus "you have three" —
            // ask here rather than keep a second copy of the rule.
            'profile_completion' => $this->sessions->profileCompletion($user, $memberships),
        ], 'Profile retrieved successfully');
    }

    public function switchOrganization(Request $request): JsonResponse
    {
        $request->validate([
            'organization_id' => ['required', 'exists:organizations,id'],
        ]);

        $user = $request->user();
        $orgId = $request->input('organization_id');

        $membership = $user->firstActiveMembership($orgId);

        if (!$membership && !$user->isPlatformOperator()) {
            return ApiResponse::error(
                'You are not an active member of this organization',
                'ORGANIZATION_NOT_MEMBER',
                Response::HTTP_FORBIDDEN
            );
        }

        $org = $membership?->organization ?? Organization::findOrFail($orgId);

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
     * The write and the sessions it ends are one act, in
     * `PasswordCredentialService`. What is chosen here is which token is
     * spared — the one making this call, so a person is not logged out by their
     * own security action — because a password change that left the other tokens
     * alive would be a formality.
     *
     * An account with no local password — one opened from a Google assertion —
     * has no current password to name, and asking for one would lock it out of
     * the credential it does not hold. For that case alone the field is not
     * required, and the write becomes a first password rather than a change.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();
        $hadNoPassword = $user->password === null;

        $validated = $request->validate([
            'current_password' => [$hadNoPassword ? 'nullable' : 'required', 'string'],
            'password'         => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (! $hadNoPassword && ! $this->passwordSignIn->matches($user, $validated['current_password'])) {
            // The same answer as a wrong login: whether this account exists is
            // not this endpoint's to confirm.
            return ApiResponse::error(
                'كلمة المرور الحالية غير صحيحة',
                'CURRENT_PASSWORD_INCORRECT',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        // The token making this call is the one spared: a person must not be
        // logged out by their own security action, while every session the old
        // password minted — including a stolen one — ends with it.
        $this->credentials->setPassword($user, $validated['password'], $user->currentAccessToken()?->id);

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
