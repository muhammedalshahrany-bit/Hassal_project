<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Organization;
use App\Models\OrganizationDocument;
use App\Services\TenantContext;
use App\Support\UploadQuota;
use App\Support\WaitingTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
 */
class OrganizationDocumentController extends Controller
{
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
     * nothing more. No business role holds it (§36 — enforced at the route).
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

        $query = OrganizationDocument::query()
            ->with([
                'organization:id,name_ar,name_en,type,status,city,country_code,commercial_registration_number,vat_number,kyc_verified_at',
                'reviewer:id,name',
                'uploader:id,name',
            ]);

        if (filled($validated['status'] ?? null)) {
            $query->where('status', $validated['status']);
        } else {
            // Unfiltered means "what is waiting", not "everything ever filed".
            $query->where('status', OrganizationDocument::STATUS_PENDING);
        }

        if (filled($validated['type'] ?? null)) {
            $query->where('type', $validated['type']);
        }

        if (filled($validated['organization_id'] ?? null)) {
            $query->where('organization_id', (int) $validated['organization_id']);
        }

        if (filled($validated['search'] ?? null)) {
            $term = $validated['search'];
            $query->where(function ($q) use ($term) {
                $q->where('reference_number', 'like', "%{$term}%")
                    ->orWhere('original_name', 'like', "%{$term}%")
                    ->orWhereHas('organization', function ($org) use ($term) {
                        $org->where('name_ar', 'like', "%{$term}%")
                            ->orWhere('name_en', 'like', "%{$term}%")
                            ->orWhere('commercial_registration_number', 'like', "%{$term}%");
                    });
            });
        }

        $query->orderByRaw("case when status = 'pending' then 0 else 1 end asc")
            ->orderBy('id', 'asc');

        $page = $query->paginate((int) ($validated['per_page'] ?? 25), ['*'], 'page', (int) ($validated['page'] ?? 1));

        // Which papers each business already has accepted, fetched once for the
        // whole page rather than per row: a reviewer deciding on six copies
        // from one seller must not run the same query six times.
        $acceptedTypes = OrganizationDocument::query()
            ->where('status', OrganizationDocument::STATUS_ACCEPTED)
            ->whereIn('organization_id', collect($page->items())->pluck('organization_id')->unique()->all())
            ->get(['organization_id', 'type'])
            ->groupBy('organization_id')
            ->map(fn ($rows) => $rows->pluck('type')->unique()->values()->all());

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
                'accepted_types'  => $acceptedTypes->get($document->organization_id, []),
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

        $counts = OrganizationDocument::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $organizations = OrganizationDocument::query()
            ->selectRaw('count(distinct organization_id) as total')
            ->where('status', OrganizationDocument::STATUS_PENDING)
            ->value('total');

        return ApiResponse::success([
            'by_status' => [
                OrganizationDocument::STATUS_PENDING  => (int) ($counts[OrganizationDocument::STATUS_PENDING] ?? 0),
                OrganizationDocument::STATUS_ACCEPTED => (int) ($counts[OrganizationDocument::STATUS_ACCEPTED] ?? 0),
                OrganizationDocument::STATUS_REJECTED => (int) ($counts[OrganizationDocument::STATUS_REJECTED] ?? 0),
            ],
            'organizations_with_pending_documents' => (int) $organizations,
        ], 'ملخص مراجعة الوثائق');
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

        $documents = OrganizationDocument::query()
            ->with(['reviewer:id,name', 'uploader:id,name'])
            ->where('organization_id', $organization->id)
            ->orderByDesc('id')
            ->get();

        return ApiResponse::success([
            'organization_id' => $organization->id,
            'status'          => $organization->status,
            'accepted_types'  => $documents->where('status', OrganizationDocument::STATUS_ACCEPTED)->pluck('type')->unique()->values()->all(),
            'documents'       => $documents->map(fn (OrganizationDocument $d) => $this->shape($d))->all(),
        ], 'وثائق المنشأة');
    }

    /**
     * File one document.
     *
     * A second copy of a paper the platform already accepted is refused rather
     * than stored: an accepted certificate plus a pending new one makes the
     * queue show work that is not there, and the reviewer then has to work out
     * which copy counts.
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

        $limits = config('hassal.uploads.documents');

        $validated = $request->validate([
            'type'            => ['required', Rule::in(OrganizationDocument::TYPES)],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'file'            => ['required', 'file'],
        ]);

        $file = $request->file('file');
        $mime = $file->getMimeType() ?: 'unknown';

        if (!in_array($mime, $limits['accepted_mimes'], true)) {
            return ApiResponse::error(
                'نوع الملف غير مدعوم: ' . $mime . '؛ المدعوم: ' . implode(', ', $limits['accepted_mimes']),
                'DOCUMENT_TYPE_UNSUPPORTED',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        if ($file->getSize() > $limits['max_bytes']) {
            return ApiResponse::error(
                'حجم الملف أكبر من الحد المسموح (' . $limits['max_bytes'] . ' بايت)',
                'DOCUMENT_TOO_LARGE',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        if (OrganizationDocument::query()->where('organization_id', $organization->id)->count() >= $limits['max_per_org']) {
            return ApiResponse::error(
                'وصلت المنشأة للحد الأقصى من الوثائق (' . $limits['max_per_org'] . ')',
                'DOCUMENT_LIMIT_REACHED',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        // The tenant-wide byte ceiling, checked before anything is written or an
        // older copy deleted: a refusal on quota must leave the paper that is
        // already filed exactly where it was.
        $quota = UploadQuota::check($organization->id, (int) ($file->getSize() ?: 0));
        if (!$quota['allowed']) {
            return ApiResponse::error(
                'تجاوزت مساحة التخزين المخصصة للمنشأة (' . $quota['quota_bytes'] . ' بايت)',
                'UPLOAD_QUOTA_EXCEEDED',
                Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
                [
                    'quota_bytes'    => $quota['quota_bytes'],
                    'used_bytes'     => $quota['used_bytes'],
                    'incoming_bytes' => $quota['incoming_bytes'],
                ]
            );
        }

        if (OrganizationDocument::query()
            ->where('organization_id', $organization->id)
            ->where('type', $validated['type'])
            ->where('status', OrganizationDocument::STATUS_ACCEPTED)
            ->exists()
        ) {
            return ApiResponse::error(
                'هذه الوثيقة مقبولة مسبقًا؛ احذفها أولًا إن أردت استبدالها',
                'DOCUMENT_ALREADY_ACCEPTED',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['type' => $validated['type']]
            );
        }

        // A re-filing of a paper that was rejected replaces it instead of adding
        // a second pending copy, so the queue holds one row per open question.
        OrganizationDocument::query()
            ->where('organization_id', $organization->id)
            ->where('type', $validated['type'])
            ->where('status', OrganizationDocument::STATUS_PENDING)
            ->get()
            ->each(function (OrganizationDocument $previous) {
                $disk = Storage::disk($previous->disk);
                if ($disk->exists($previous->path)) {
                    $disk->delete($previous->path);
                }
                $previous->delete();
            });

        // From the detected bytes, not the sender's filename: the mime check
        // above is a type comparison, so a PDF named `contract.php` passes it,
        // and the extension is what a later download header is built from.
        $extension = match ($mime) {
            'application/pdf' => 'pdf',
            'image/jpeg'      => 'jpg',
            'image/png'       => 'png',
            default           => 'bin',
        };
        $path = 'organizations/' . $organization->id . '/documents/' . (string) Str::uuid() . '.' . $extension;

        // `local`, not `public`: this disk's root is `storage/app/private`, so a
        // copy of a registration certificate is never reachable by a plain
        // `/storage/...` URL. Reads go through the signed `…/file` route.
        Storage::disk('local')->putFileAs(dirname($path), $file, basename($path));

        $document = OrganizationDocument::create([
            'organization_id'  => $organization->id,
            'type'             => $validated['type'],
            'reference_number' => $validated['reference_number'] ?? null,
            'disk'             => 'local',
            'path'             => $path,
            'original_name'    => Str::limit($file->getClientOriginalName(), 250, ''),
            'mime_type'        => $mime,
            'size_bytes'       => $file->getSize() ?: 0,
            'status'           => OrganizationDocument::STATUS_PENDING,
            'uploaded_by'      => $request->user()->id,
        ]);

        return ApiResponse::success(
            $this->shape($document),
            'تم رفع الوثيقة، وهي الآن بانتظار مراجعة فريق المنصة',
            Response::HTTP_CREATED
        );
    }

    /**
     * Withdraw a filed copy before anyone rules on it.
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

        if ($document->status !== OrganizationDocument::STATUS_PENDING) {
            return ApiResponse::error(
                'لا يمكن حذف وثيقة صدر قرار بشأنها؛ اطلب من فريق المنصة إعادة النظر فيها',
                'DOCUMENT_ALREADY_REVIEWED',
                Response::HTTP_FORBIDDEN,
                ['status' => $document->status]
            );
        }

        $disk = Storage::disk($document->disk);

        if ($disk->exists($document->path)) {
            $disk->delete($document->path);
        }

        $document->delete();

        return ApiResponse::success(['id' => $document->id, 'deleted' => true], 'تم حذف الوثيقة');
    }

    /**
     * The platform's decision on one document.
     *
     * A rejection carries a reason, because "your papers were rejected" is not
     * actionable and an applicant who cannot tell which paper failed resubmits
     * the same one forever.
     */
    public function review(Request $request, int $organizationId, int $documentId): JsonResponse
    {
        if (!$request->user()->hasRole('SuperAdmin') && !$request->user()->can('organizations.verify')) {
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
            'action' => ['required', Rule::in(['accept', 'reject'])],
            'reason' => ['required_if:action,reject', 'nullable', 'string', 'max:1000'],
        ]);

        $document->update([
            'status'        => $validated['action'] === 'accept'
                ? OrganizationDocument::STATUS_ACCEPTED
                : OrganizationDocument::STATUS_REJECTED,
            'reviewed_by'   => $request->user()->id,
            'reviewed_at'   => now(),
            'review_reason' => $validated['reason'] ?? null,
        ]);

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
            && ($user->hasRole('SuperAdmin') || $user->can('organizations.verify'));
    }

    private function maySee(Request $request, ?Organization $organization): bool
    {
        if (!$organization) {
            return false;
        }

        // The guard is the one `review()` applies, on purpose: whoever may
        // decide a paper must be able to read it, or the console would ask a
        // reviewer to accept a file they cannot open. A business holds
        // `organizations.manage` for itself and never sees another's papers,
        // because that permission is not this one.
        return $request->user()->hasRole('SuperAdmin')
            || $request->user()->can('organizations.verify')
            || TenantContext::id() === $organization->id;
    }

    private function mayFile(Request $request, ?Organization $organization): bool
    {
        if (!$organization) {
            return false;
        }

        if ($request->user()->hasRole('SuperAdmin')) {
            return true;
        }

        // Filing is an act inside one's own business, and the route already
        // carries `permission:organizations.manage`; this is the part a
        // permission cannot express — that the manage right is scoped to the
        // organization the caller is actually acting for.
        return TenantContext::id() === $organization->id;
    }
}
