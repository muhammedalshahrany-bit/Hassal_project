<?php

namespace App\Organizations\Services;

use App\Core\Support\UploadProbe;
use App\Core\Support\UploadQuota;
use App\Identity\Models\User;
use App\Core\Exceptions\ApiRefused;
use App\Organizations\Models\Organization;
use App\Organizations\Models\OrganizationDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * A business's legal papers: filing them, withdrawing an unanswered one, and
 * ruling on what the platform was asked to accept.
 *
 * The controller decides who may knock; this class decides what happens to the
 * file and to the record of it. The order of its bounds is a decision, not an
 * accident: size is checked before type because a refusal on bytes should not
 * spend a read of the file's head, and the quota is checked before anything is
 * written or an older copy deleted, because a refusal on quota must leave the
 * paper that is already filed exactly where it was.
 *
 * What lands on disk is named and typed from the detected bytes, never from
 * the sender's filename: a PDF named `contract.php` passes a name comparison,
 * and the extension is what a later download header is built from.
 */
class OrganizationDocumentDesk
{
    /**
     * Store one filed paper, replacing the open question it re-files.
     *
     * A second copy of a paper the platform already accepted is refused rather
     * than stored: an accepted certificate plus a pending new one makes the
     * queue show work that is not there, and the reviewer then has to work out
     * which copy counts. A re-filing of a paper that was *rejected* replaces
     * it instead of adding a second pending copy, so the queue holds one row
     * per open question.
     *
     * @param  array<string, mixed>  $validated
     *
     * @throws ApiRefused
     */
    public function file(Organization $organization, User $uploader, array $validated, UploadedFile $file): OrganizationDocument
    {
        $limits = config('hassal.uploads.documents');

        // Size before type: a refusal on bytes should not spend a read of the
        // file's head, and the ceiling is the cheaper of the two answers.
        if ($file->getSize() > $limits['max_bytes']) {
            throw new ApiRefused(
                'حجم الملف أكبر من الحد المسموح (' . $limits['max_bytes'] . ' بايت)',
                'DOCUMENT_TOO_LARGE',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        // Both halves of the type decision come from one place now. The file's
        // leading bytes are compared with what `finfo` sampled, and the
        // extension the copy is stored under is the one that detected type has —
        // never the name the sender chose. See UploadProbe for why a single
        // detector is not enough.
        $probe = UploadProbe::isAcceptable($file, $limits['accepted_mimes']);

        if (! $probe['ok']) {
            throw new ApiRefused(
                $probe['reason'] === 'unsupported_type'
                    ? 'نوع الملف غير مدعوم: ' . $probe['mime'] . '؛ المدعوم: ' . implode(', ', $limits['accepted_mimes'])
                    : 'تعذّر التحقق من نوع الملف (' . $probe['reason'] . ')',
                'DOCUMENT_TYPE_UNSUPPORTED',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['detected' => $probe['mime'], 'reason' => $probe['reason']]
            );
        }

        if (OrganizationDocument::query()->where('organization_id', $organization->id)->count() >= $limits['max_per_org']) {
            throw new ApiRefused(
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
            throw new ApiRefused(
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
            throw new ApiRefused(
                'هذه الوثيقة مقبولة مسبقًا؛ احذفها أولًا إن أردت استبدالها',
                'DOCUMENT_ALREADY_ACCEPTED',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['type' => $validated['type']]
            );
        }

        $this->discardPendingCopies($organization, $validated['type']);

        // From the detected bytes, not the sender's filename.
        $path = 'organizations/' . $organization->id . '/documents/' . (string) Str::uuid() . '.' . $probe['extension'];

        // `local`, not `public`: this disk's root is `storage/app/private`, so a
        // copy of a registration certificate is never reachable by a plain
        // `/storage/...` URL. Reads go through the signed `…/file` route.
        Storage::disk('local')->putFileAs(dirname($path), $file, basename($path));

        return OrganizationDocument::create([
            'organization_id'  => $organization->id,
            'type'             => $validated['type'],
            'reference_number' => $validated['reference_number'] ?? null,
            'disk'             => 'local',
            'path'             => $path,
            'original_name'    => Str::limit($file->getClientOriginalName(), 250, ''),
            'mime_type'        => $probe['mime'],
            'size_bytes'       => $file->getSize() ?: 0,
            'status'           => OrganizationDocument::STATUS_PENDING,
            'uploaded_by'      => $uploader->id,
        ]);
    }

    /**
     * Withdraw a copy the business filed itself.
     *
     * Only an unanswered question may be taken back. A paper the platform has
     * already ruled on is part of a decision that was made — deleting it would
     * leave the verdict describing a document nobody can open, and the applicant
     * would be able to erase the evidence a rejection was based on. The way back
     * for that is a re-filing after the review, which is why the refusal names
     * the platform rather than the file.
     *
     * @throws ApiRefused
     */
    public function withdraw(OrganizationDocument $document): void
    {
        if ($document->status !== OrganizationDocument::STATUS_PENDING) {
            throw new ApiRefused(
                'لا يمكن حذف وثيقة صدر قرار بشأنها؛ اطلب من فريق المنصة إعادة النظر فيها',
                'DOCUMENT_ALREADY_REVIEWED',
                Response::HTTP_FORBIDDEN,
                ['status' => $document->status]
            );
        }

        $this->discard($document);
    }

    /**
     * The platform's decision on one filed paper.
     *
     * A rejection carries a reason, because "your papers were rejected" is not
     * actionable and an applicant who cannot tell which paper failed resubmits
     * the same one forever. The ruling is one write of four columns — state,
     * who, when, why — and it is here rather than in the door that read the
     * form so that a decision cannot be recorded half: a verdict with no
     * reviewer is a verdict the platform cannot defend later.
     *
     * @param  array{action: string, reason?: string|null}  $validated
     */
    public function rule(OrganizationDocument $document, array $validated, User $reviewer): OrganizationDocument
    {
        $document->update([
            'status'        => $validated['action'] === OrganizationDocument::ACTION_ACCEPT
                ? OrganizationDocument::STATUS_ACCEPTED
                : OrganizationDocument::STATUS_REJECTED,
            'reviewed_by'   => $reviewer->id,
            'reviewed_at'   => now(),
            'review_reason' => $validated['reason'] ?? null,
        ]);

        return $document;
    }

    /**
     * Delete a copy's bytes and its row together. A row outliving its bytes
     * would show a reviewer a link to nothing; a file outliving its row would
     * be unreadable paperwork taking up the tenant's quota forever.
     */
    public function discard(OrganizationDocument $document): void
    {
        $disk = Storage::disk($document->disk);

        if ($disk->exists($document->path)) {
            $disk->delete($document->path);
        }

        $document->delete();
    }

    /**
     * A re-filing of a rejected paper replaces the open question, it does not
     * add a second one.
     */
    private function discardPendingCopies(Organization $organization, string $type): void
    {
        OrganizationDocument::query()
            ->where('organization_id', $organization->id)
            ->where('type', $type)
            ->where('status', OrganizationDocument::STATUS_PENDING)
            ->get()
            ->each(fn (OrganizationDocument $previous) => $this->discard($previous));
    }
}
