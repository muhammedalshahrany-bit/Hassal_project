<?php

namespace App\Organizations\Controllers;

use App\Core\Http\Controllers\Controller;
use App\Core\Http\ApiResponse;
use App\Core\Exceptions\ApiRefused;
use App\Organizations\Models\Organization;
use App\Organizations\Models\OrganizationDocument;
use App\Organizations\Services\OrganizationDocumentDesk;
use App\Organizations\Services\OrganizationDocumentQueries;
use App\Core\Tenant\TenantContext;
use App\Core\Support\WaitingTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The legal papers a business files, and the platform's decision on each one.
 *
 * Two separate things, kept separate on purpose:
 *
 *   * Filing documents (`organizations.manage`, inside one's own business) is
 *     always available — an applicant that cannot upload its registration
 *     certificate because it is not verified has no way to become verified.
 *   * Deciding a document is accepted or rejected is `organizations.verify`,
 *     which no business role holds.
 *
 * Neither says anything about an official register. `accepted` here means a
 * member of this platform's staff read this copy and it matched what the
 * applicant claims. The number itself is not looked up in any ministry's
 * system anywhere in this codebase, so no message or label may imply one.
 *
 * The organization's own status is still decided on `PUT /organizations/{id}
 * /verification`, which is where the marketplace gate opens. Filing papers does
 * not open it by itself, and rejecting one document does not close it — the
 * commercial verdict is one decision, recorded once.
 *
 * What happens to the bytes — the bounds a filing must clear and the copies a
 * re-filing replaces — is `OrganizationDocumentDesk`'s; which rows may be read,
 * with what loaded and in what order, is `OrganizationDocumentQueries`'s; and
 * who may ask for any of it is answered here, against the caller and the tenant.
 */
class OrganizationDocumentController extends Controller
{
    public function __construct(
        private readonly OrganizationDocumentDesk $desk,
        private readonly OrganizationDocumentQueries $documents,
    ) {}

    /**
     * The platform's review desk: every filed paper, across every business.
     *
     * `index()` answers one business at a time, which is right for that
     * business and wrong for staff: a reviewer with six applicants waiting had
     * to open each organization row in the console and expand its papers one by
     * one, with no way to see what is oldest or who is still waiting.
     *
     * Pending copies sort first because they are the work. Within that, the
     * order is by id, which is the order they arrived.
     *
     * `organizations.verify` is the same capability that flips a business to
     * `verified`, so the desk holds exactly what the decision is made from and
     * nothing more. No business role holds it — enforced at the route.
     */
    public function queue(Request $request): JsonResponse
    {
        if (!$this->isPlatformStaff($request)) {
            return ApiResponse::error('قائمة مراجعة الوثائق لفريق المنصة', 'PERMISSION_DENIED', Response::HTTP_FORBIDDEN);
        }

        $validated = $request->validate([
            'status'          => ['nullable', Rule::in(OrganizationDocument::STATUSES)],
            'type'            => ['nullable', Rule::in(OrganizationDocument::TYPES)],
            'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'search'          => ['nullable', 'string', 'max:120'],
            'page'            => ['nullable', 'integer', 'min:1'],
            'per_page'        => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = $this->documents->reviewQueue($validated);

        // Which papers each business already has accepted, fetched once for the
        // whole page rather than per row: a reviewer deciding on six copies
        // from one seller must not run the same query six times.
        $acceptedTypes = $this->documents->acceptedTypesFor(
            collect($page->items())->pluck('organization_id')->unique()->values()->all()
        );

        $rows = collect($page->items())->map(function (OrganizationDocument $document) use ($acceptedTypes) {
            $shaped = $this->shape($document);

            $shaped['organization'] = $document->organization ? [
                'id'              => $document->organization->id,
                'name_ar'         => $document->organization->name_ar,
                'name_en'         => $document->organization->name_en,
                'type'            => $document->organization->type,
                'status'          => $document->organization->status,
                'city'            => $document->organization->city,
                'country_code'    => $document->organization->country_code,
                'registration_no' => $document->organization->commercial_registration_number,
                'tax_no'          => $document->organization->vat_number,
                // When this platform's staff last ruled on the business itself.
                // Nothing in this codebase reads a ministry register, so this is
                // an internal timestamp and must not be labelled a confirmation.
                'kyc_verified_at' => $document->organization->kyc_verified_at,
                'accepted_types'  => $acceptedTypes[$document->organization_id] ?? [],
            ] : null;

            $shaped['waiting_hours'] = $document->status === OrganizationDocument::STATUS_PENDING
                ? WaitingTime::hoursSince($document->created_at)
                : null;

            return $shaped;
        })->all();

        $page->setCollection(collect($rows));

        return ApiResponse::paginated($page, 'قائمة مراجعة وثائق المنشآت');
    }

    /**
     * How many filed papers sit in each state, counted by the server.
     *
     * The console prints these next to its tabs. It is given the numbers rather
     * than writing its own, because a tab labelled "بانتظار المراجعة" with no
     * count behind it is a claim about workload nobody verified.
     */
    public function summary(Request $request): JsonResponse
    {
        if (!$this->isPlatformStaff($request)) {
            return ApiResponse::error('ملخص مراجعة الوثائق لفريق المنصة', 'PERMISSION_DENIED', Response::HTTP_FORBIDDEN);
        }

        return ApiResponse::success(
            $this->documents->summary(),
            'ملخص مراجعة الوثائق'
        );
    }

    public function index(Request $request, int $organizationId): JsonResponse
    {
        $organization = Organization::find($organizationId);

        if (!$organization) {
            return ApiResponse::error('المنشأة غير موجودة', 'NOT_FOUND', Response::HTTP_NOT_FOUND);
        }

        if (!$this->maySee($request, $organization)) {
            return ApiResponse::error('لا يمكنك الاطلاع على وثائق هذه المنشأة', 'FORBIDDEN', Response::HTTP_FORBIDDEN);
        }

        $documents = $this->documents->forOrganization($organization->id);

        return ApiResponse::success([
            'organization_id' => $organization->id,
            'status'          => $organization->status,
            'accepted_types'  => $this->documents->acceptedTypesFor([$organization->id])[$organization->id] ?? [],
            'documents'       => $documents->map(fn (OrganizationDocument $d) => $this->shape($d))->all(),
        ], 'وثائق المنشأة');
    }

    /**
     * File one document. The bounds (size, type, count, quota) and the
     * replacement of an open question are the desk's; they answer through
     * `refused()` with the exact sentence the client is owed.
     */
    public function store(Request $request, int $organizationId): JsonResponse
    {
        $organization = Organization::find($organizationId);

        if (!$organization) {
            return ApiResponse::error('المنشأة غير موجودة', 'NOT_FOUND', Response::HTTP_NOT_FOUND);
        }

        if (!$this->mayFile($request, $organization)) {
            return ApiResponse::error('لا يمكنك رفع وثائق هذه المنشأة', 'FORBIDDEN', Response::HTTP_FORBIDDEN);
        }

        $validated = $request->validate([
            'type'            => ['required', Rule::in(OrganizationDocument::TYPES)],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'file'            => ['required', 'file'],
        ]);

        try {
            $document = $this->desk->file($organization, $request->user(), $validated, $request->file('file'));
        } catch (ApiRefused $refused) {
            return $this->refused($refused);
        }

        return ApiResponse::success(
            $this->shape($document),
            'تم رفع الوثيقة، وهي الآن بانتظار مراجعة فريق المنصة',
            Response::HTTP_CREATED
        );
    }

    /**
     * Withdraw a filed copy before anyone rules on it.
     *
     * "Before anyone rules on it" is the desk's rule, not this door's — see
     * `OrganizationDocumentDesk::withdraw()`.
     */
    public function destroy(Request $request, int $organizationId, int $documentId): JsonResponse
    {
        $document = OrganizationDocument::query()
            ->where('id', $documentId)
            ->where('organization_id', $organizationId)
            ->first();

        if (!$document) {
            return ApiResponse::error('الوثيقة غير موجودة', 'NOT_FOUND', Response::HTTP_NOT_FOUND);
        }

        $organization = Organization::find($organizationId);

        if (!$this->mayFile($request, $organization)) {
            return ApiResponse::error('لا يمكنك حذف وثائق هذه المنشأة', 'FORBIDDEN', Response::HTTP_FORBIDDEN);
        }

        try {
            $this->desk->withdraw($document);
        } catch (ApiRefused $refused) {
            return $this->refused($refused);
        }

        return ApiResponse::success(['id' => $document->id, 'deleted' => true], 'تم حذف الوثيقة');
    }

    /**
     * The platform's decision on one document.
     *
     * A rejection carries a reason, and the four columns a ruling is made of are
     * written together by `OrganizationDocumentDesk::rule()` — this door reads the
     * form and answers.
     */
    public function review(Request $request, int $organizationId, int $documentId): JsonResponse
    {
        if (!$this->isPlatformStaff($request)) {
            return ApiResponse::error('مراجعة الوثائق من صلاحيات فريق المنصة', 'PERMISSION_DENIED', Response::HTTP_FORBIDDEN);
        }

        $document = OrganizationDocument::query()
            ->with('organization:id,name_ar,status')
            ->where('id', $documentId)
            ->where('organization_id', $organizationId)
            ->first();

        if (!$document) {
            return ApiResponse::error('الوثيقة غير موجودة', 'NOT_FOUND', Response::HTTP_NOT_FOUND);
        }

        $validated = $request->validate([
            'action' => ['required', Rule::in(OrganizationDocument::REVIEW_ACTIONS)],
            'reason' => ['required_if:action,'.OrganizationDocument::ACTION_REJECT, 'nullable', 'string', 'max:1000'],
        ]);

        $this->desk->rule($document, $validated, $request->user());

        return ApiResponse::success($this->shape($document->fresh()), 'تم تسجيل قرار الوثيقة');
    }

    /**
     * Stream one filed paper, for the signed link `OrganizationDocument::url()`
     * minted.
     *
     * This route carries no token: a browser following a link cannot attach an
     * `Authorization` header, so the signature *is* the authorisation — it could
     * only have been minted by a request that already passed `maySee()`, and it
     * lapses. What is checked here is that the row and its bytes still exist, and
     * that the bytes go out as the type the server detected at upload rather than
     * the type whoever filed them claimed.
     */
    public function file(Request $request, int $organizationId, int $documentId): StreamedResponse|JsonResponse
    {
        $document = OrganizationDocument::query()
            ->where('id', $documentId)
            ->where('organization_id', $organizationId)
            ->first();

        $disk = $document ? Storage::disk($document->disk) : null;

        if (!$document || $disk === null || !$disk->exists($document->path)) {
            return ApiResponse::error('الوثيقة غير متاحة', 'NOT_FOUND', Response::HTTP_NOT_FOUND);
        }

        return $disk->response(
            $document->path,
            $document->original_name ?: basename($document->path),
            ['Content-Type' => $document->mime_type ?: 'application/octet-stream'],
            'inline',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function shape(OrganizationDocument $document): array
    {
        return [
            'id'               => $document->id,
            'organization_id'  => $document->organization_id,
            'type'             => $document->type,
            'reference_number' => $document->reference_number,
            'status'           => $document->status,
            'original_name'    => $document->original_name,
            'mime_type'        => $document->mime_type,
            'size_bytes'       => $document->size_bytes,
            'url'              => $document->url(),
            'uploaded_at'      => $document->created_at,
            'uploaded_by_name' => $document->uploader?->name,
            'reviewed_at'      => $document->reviewed_at,
            'reviewed_by_name' => $document->reviewer?->name,
            'review_reason'    => $document->review_reason,
        ];
    }

    /**
     * Whether this caller rules on other people's paperwork.
     *
     * `organizations.verify` is the capability, and `SuperAdmin` is the role the
     * installer gives the platform's own account before any permission row is
     * read. A SellerAdmin holds `organizations.manage` for their own company and
     * is not staff by any reading of it.
     */
    private function isPlatformStaff(Request $request): bool
    {
        $user = $request->user();

        return $user !== null
            && ($user->isPlatformOperator() || $user->can('organizations.verify'));
    }

    private function maySee(Request $request, ?Organization $organization): bool
    {
        // The guard is the one `review()` applies, on purpose: whoever may
        // decide a paper must be able to read it, or the console would ask a
        // reviewer to accept a file they cannot open. That question is asked
        // through `isPlatformStaff()` rather than written out again here,
        // because the two spellings of it drifting apart is exactly the failure
        // this sentence describes. A business holds `organizations.manage` for
        // itself and never sees another's papers, because that permission is
        // not this one.
        return $organization !== null
            && ($this->isPlatformStaff($request) || TenantContext::id() === $organization->id);
    }

    private function mayFile(Request $request, ?Organization $organization): bool
    {
        if (!$organization) {
            return false;
        }

        if ($request->user()->isPlatformOperator()) {
            return true;
        }

        // Filing is an act inside one's own business, and the route already
        // carries `permission:organizations.manage`; this is the part a
        // permission cannot express — that the manage right is scoped to the
        // organization the caller is actually acting for.
        return TenantContext::id() === $organization->id;
    }
}
