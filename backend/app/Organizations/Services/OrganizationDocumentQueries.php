<?php

namespace App\Organizations\Services;

use App\Organizations\Models\OrganizationDocument;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * Which filed papers may be read, in what order, and with what loaded.
 *
 * The write half of this area is `OrganizationDocumentDesk` — a filing, a
 * withdrawal, a ruling. This is the read half, and it is separate because the
 * two answer different questions: the desk decides what happens to bytes and to
 * a record, while this class decides only what a caller is allowed to *see* and
 * how much it costs to say so.
 *
 * The costs are the reason it lives here at all. The review desk shows rows from
 * several businesses in one list, and each row needs to say which papers that
 * business already has accepted — otherwise a reviewer sees a pending copy of a
 * certificate the platform accepted three rows earlier and rules on it twice.
 * That fact is one query for the whole page, not one per row, and the page is
 * built here so the door that draws it cannot forget the batch.
 *
 * Who may ask is not answered here. Every method of this class returns rows from
 * every business or from the one named; the caller's standing is checked by the
 * controller before it asks, and a read that needs a permission it does not have
 * never reaches this file.
 */
final class OrganizationDocumentQueries
{
    /**
     * The platform's waiting list, one page of it.
     *
     * With no status filter the page is the work in front of a reviewer —
     * pending copies — rather than the whole archive, and pending rows lead
     * within a filtered page for the same reason. The order within that is by
     * id, which is the order the papers arrived.
     *
     * @param  array<string, mixed>  $filters  validated input: status, type, organization_id, search, page, per_page
     *
     * @return LengthAwarePaginator<int, OrganizationDocument>
     */
    public function reviewQueue(array $filters): LengthAwarePaginator
    {
        $query = OrganizationDocument::query()
            ->with([
                'organization:id,name_ar,name_en,type,status,city,country_code,commercial_registration_number,vat_number,kyc_verified_at',
                'reviewer:id,name',
                'uploader:id,name',
            ]);

        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        } else {
            $query->where('status', OrganizationDocument::STATUS_PENDING);
        }

        if (filled($filters['type'] ?? null)) {
            $query->where('type', $filters['type']);
        }

        if (filled($filters['organization_id'] ?? null)) {
            $query->where('organization_id', (int) $filters['organization_id']);
        }

        if (filled($filters['search'] ?? null)) {
            $term = $filters['search'];
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

        return $query->paginate(
            (int) ($filters['per_page'] ?? 25),
            ['*'],
            'page',
            (int) ($filters['page'] ?? 1)
        );
    }

    /**
     * Which papers each of these businesses already has accepted.
     *
     * One query for a whole page of rows: a reviewer looking at six copies from
     * one seller must not run this question six times, and the answer must not
     * be assembled per row where a second caller would assemble it differently.
     *
     * @param  list<int>  $organizationIds
     *
     * @return array<int, list<string>>  organization id => the types accepted there
     */
    public function acceptedTypesFor(array $organizationIds): array
    {
        if ($organizationIds === []) {
            return [];
        }

        return OrganizationDocument::query()
            ->where('status', OrganizationDocument::STATUS_ACCEPTED)
            ->whereIn('organization_id', $organizationIds)
            ->get(['organization_id', 'type'])
            ->groupBy('organization_id')
            ->map(fn ($rows) => $rows->pluck('type')->unique()->values()->all())
            ->all();
    }

    /**
     * Every paper one business has filed, newest first, with its people loaded.
     *
     * @return Collection<int, OrganizationDocument>
     */
    public function forOrganization(int $organizationId): Collection
    {
        return OrganizationDocument::query()
            ->with(['reviewer:id,name', 'uploader:id,name'])
            ->where('organization_id', $organizationId)
            ->orderByDesc('id')
            ->get();
    }

    /**
     * How many filed papers sit in each state, and how many businesses are waiting.
     *
     * Counted here rather than by the console because the numbers appear beside
     * the same rows the queue pages, and a tab labelled with a count the client
     * wrote is a claim nobody checked. A state with no rows is answered as zero,
     * not as absent, so the console never has to guess what a missing key means.
     *
     * @return array{by_status: array<string, int>, organizations_with_pending_documents: int}
     */
    public function summary(): array
    {
        $counts = OrganizationDocument::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $organizations = OrganizationDocument::query()
            ->selectRaw('count(distinct organization_id) as total')
            ->where('status', OrganizationDocument::STATUS_PENDING)
            ->value('total');

        return [
            'by_status' => [
                OrganizationDocument::STATUS_PENDING  => (int) ($counts[OrganizationDocument::STATUS_PENDING] ?? 0),
                OrganizationDocument::STATUS_ACCEPTED => (int) ($counts[OrganizationDocument::STATUS_ACCEPTED] ?? 0),
                OrganizationDocument::STATUS_REJECTED => (int) ($counts[OrganizationDocument::STATUS_REJECTED] ?? 0),
            ],
            'organizations_with_pending_documents' => (int) $organizations,
        ];
    }
}
