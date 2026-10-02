<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * A legal paper a business filed with the platform, and what was decided on it.
 *
 * `type` is a closed vocabulary because the reviewer's console has to be able
 * to say "no registration certificate has been filed", which free text never
 * allows. Accepted means a staff member read this copy; it does not mean any
 * government register confirmed a number.
 */
class OrganizationDocument extends Model
{
    use HasFactory;

    public const TYPE_COMMERCIAL_REGISTRATION = 'commercial_registration';
    public const TYPE_TAX_CERTIFICATE        = 'tax_certificate';
    public const TYPE_TRADE_LICENSE          = 'trade_license';
    public const TYPE_FOUNDING_STATUTE       = 'founding_statute';
    public const TYPE_ID_PROOF               = 'id_proof';

    // The national ID card, photographed in two shots. Two rows rather than one,
    // because a reviewer rules on each side on its own: a legible front with a
    // blurred back is one accepted paper and one rejected, and a single row
    // could carry only one of those two answers.
    //
    // The card belongs to the person filing on the business's behalf, not to the
    // business. Neither row says that person's identity was confirmed against
    // any register — the platform has read the photographs it was handed.
    public const TYPE_ID_CARD_FRONT          = 'id_card_front';
    public const TYPE_ID_CARD_BACK           = 'id_card_back';

    /** The identity card counts as complete only when both sides are accepted. */
    public const ID_CARD_SIDES = [
        self::TYPE_ID_CARD_FRONT,
        self::TYPE_ID_CARD_BACK,
    ];

    public const TYPE_OTHER                  = 'other';

    public const TYPES = [
        self::TYPE_COMMERCIAL_REGISTRATION,
        self::TYPE_TAX_CERTIFICATE,
        self::TYPE_TRADE_LICENSE,
        self::TYPE_FOUNDING_STATUTE,
        self::TYPE_ID_PROOF,
        self::TYPE_ID_CARD_FRONT,
        self::TYPE_ID_CARD_BACK,
        self::TYPE_OTHER,
    ];

    public const STATUS_PENDING  = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_ACCEPTED, self::STATUS_REJECTED];

    protected $fillable = [
        'organization_id',
        'type',
        'reference_number',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size_bytes',
        'status',
        'uploaded_by',
        'reviewed_by',
        'reviewed_at',
        'review_reason',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * A short-lived signed link to this paper, or `null` when the file is gone.
     *
     * The documents disk is private, so there is no public path to hand out.
     * A permanent URL would be a worse leak than the unlisted UUID that
     * protected the file before: one forwarded link, one browser history
     * entry, one proxy log, and a business's registration certificate is
     * readable forever by whoever holds it. Fifteen minutes bounds that to
     * the window a reviewer actually needs to open the paper in front of them,
     * and the answer to a lapsed link is to reload the list rather than to
     * make a permanent exception.
     */
    public function url(): ?string
    {
        if (!Storage::disk($this->disk)->exists($this->path)) {
            return null;
        }

        return URL::temporarySignedRoute(
            'organization-documents.file',
            now()->addMinutes(15),
            ['organizationId' => $this->organization_id, 'documentId' => $this->id],
        );
    }
}
