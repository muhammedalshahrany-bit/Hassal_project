<?php

namespace App\Support;

use Exception;

/**
 * A handover code the platform will not accept, and the reason it will not.
 *
 * Every other refusal on this API is a controller comparing a request with a
 * rule and answering itself. This one is an exception because the rule is shared:
 * the scan endpoint, the state machine that gates `COMPLETED`, and the tests that
 * prove a deal cannot be closed on an opinion all ask the same question, and they
 * must run out of the same place with the same word on it.
 *
 * The `code` is what a client switches on — the message is for a log. That
 * separation is why the codes here are named in `API_CONTRACT.md` §4.7: an
 * operator at a loading bay needs "this code was already spent two minutes ago",
 * not a sentence they have to pattern-match.
 */
class HandoverRefusal extends Exception
{
    /** The code was never issued, or belongs to another order. */
    public const CODE_INVALID = 'HANDOVER_CODE_INVALID';

    /** The code is this order's, and it has already been scanned. */
    public const CODE_ALREADY_USED = 'HANDOVER_ALREADY_USED';

    /** The order was called off, so its proof of delivery was retired with it. */
    public const CODE_INVALIDATED = 'HANDOVER_INVALIDATED';

    /** No code has been issued because the order has not been accepted yet. */
    public const NOT_ISSUED = 'HANDOVER_NOT_ISSUED';

    /** The order is not at the point in its life where goods are being handed over. */
    public const NOT_AT_DELIVERY = 'HANDOVER_NOT_AT_DELIVERY';

    /** The order is being closed without a spent code behind it. */
    public const NOT_VERIFIED = 'HANDOVER_NOT_VERIFIED';

    public function __construct(
        string $message,
        public readonly string $refusalCode
    ) {
        parent::__construct($message);
    }
}
