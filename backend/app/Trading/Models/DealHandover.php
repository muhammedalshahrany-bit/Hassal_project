<?php

namespace App\Trading\Models;

use App\Identity\Models\User;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\UniqueConstraintViolationException;
use RuntimeException;

/**
 * The one code that attests that a given order's goods changed hands.
 *
 * Reading the rules off this class is enough to know what the platform will and
 * will not accept as a completed deal; the endpoint and the state machine both
 * ask these questions here rather than keeping their own copy.
 */
class DealHandover extends Model
{
    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_USED = 'USED';

    public const STATUS_INVALIDATED = 'INVALIDATED';

    /**
     * The characters a code is built from.
     *
     * `0O1IBS` are left out: they are the letters that turn "the driver read it
     * wrong" into "the buyer's order was closed against someone else's goods".
     * Sixteen characters from a 30-symbol alphabet is ~48 bits, which is not a
     * guessable number and still fits on a printed label and in a text field.
     */
    public const ALPHABET = '23456789ACDEFGHJKLMNPQRTUVWXYZ';

    public const CODE_LENGTH = 16;

    protected $fillable = [
        'deal_id',
        'code',
        'status',
        'issued_by_user_id',
        'verified_by_user_id',
        'verified_by_organization_id',
        'issued_at',
        'verified_at',
        'invalidated_at',
        'verification_notes',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'verified_at' => 'datetime',
            'invalidated_at' => 'datetime',
        ];
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function verifiedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * The string a QR reader is expected to produce for this code.
     *
     * A camera at a loading bay scans a picture, not a form field, and the
     * picture has to say what kind of thing it is — otherwise the platform's
     * own code is indistinguishable from any other QR a phone meets, and the
     * scanner app has nothing to do with it but display a mystery. The deal
     * number is in the payload so a rescanned photo years later still names the
     * order it belonged to; the check is against `code`, which is the secret.
     */
    public function payload(Deal $deal): string
    {
        return 'HASSAL:HANDOVER:' . $deal->deal_number . ':' . $this->code;
    }

    /**
     * Pull a code back out of whatever a scanner handed over.
     *
     * Cameras return the whole payload; a person typing never sees the prefix.
     * Both are the same act — attesting a handover — so both are accepted, and
     * the shape is stripped here once instead of in two clients.
     */
    public static function normalizeCode(string $raw): string
    {
        $trimmed = trim($raw);

        if (preg_match('/HASSAL:HANDOVER:[^:]+:([A-Za-z0-9]+)$/', $trimmed, $matches) === 1) {
            $trimmed = $matches[1];
        }

        return strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $trimmed) ?? '');
    }

    /**
     * Issue the order's code, or hand back the one it already has.
     *
     * Idempotent because the acceptance is what issues it, and an acceptance can
     * be retried after a timeout — a second code for one order would mean two
     * possible proofs for the same delivery, and only one of them would be true.
     *
     * The insert is wrapped in a retry rather than a check-then-create: `code`
     * is unique, so the race is resolved by the database and not by two reads
     * that happened to disagree.
     */
    public static function issueFor(Deal $deal, ?int $issuedByUserId = null): self
    {
        $existing = self::where('deal_id', $deal->id)->first();
        if ($existing !== null) {
            return $existing;
        }

        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                return self::create([
                    'deal_id' => $deal->id,
                    'code' => self::randomCode(),
                    'status' => self::STATUS_ACTIVE,
                    'issued_by_user_id' => $issuedByUserId,
                    'issued_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException $race) {
                // Either the same order was issued by the retry behind us — the
                // normal case, and the answer we want — or the random code
                // collided with another order's, in which case drawing again is
                // correct. `deal_id` is unique, so the first query decides.
                $concurrent = self::where('deal_id', $deal->id)->first();
                if ($concurrent !== null) {
                    return $concurrent;
                }
            }
        }

        throw new RuntimeException('Could not issue a handover code for this order.');
    }

    /**
     * Invalidate an order's code because the order will never be handed over.
     *
     * A cancelled order with a live code is a live proof of a delivery that
     * cannot happen; spending it after the cancellation would have to be
     * refused by state checks alone, and a code that has been retired does not
     * need anybody to remember to check.
     */
    public function invalidate(): void
    {
        if ($this->status === self::STATUS_USED) {
            return;
        }

        $this->forceFill([
            'status' => self::STATUS_INVALIDATED,
            'invalidated_at' => now(),
        ])->save();
    }

    /**
     * Find the code as it was presented, wherever it came from.
     *
     * The lookup is on the column's own `utf8mb4_bin` collation, so "lowercase
     * typed by hand" is not a second valid spelling of a code the unique index
     * already treats as one value — and `lowercase` is not a code at all.
     */
    public static function findByCode(string $code): ?self
    {
        $normalized = self::normalizeCode($code);

        return $normalized === ''
            ? null
            : self::where('code', $normalized)->first();
    }

    private static function randomCode(): string
    {
        $alphabet = self::ALPHABET;
        $last = strlen($alphabet) - 1;
        $code = '';

        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= $alphabet[random_int(0, $last)];
        }

        return $code;
    }
}
