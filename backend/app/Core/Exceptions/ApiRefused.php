<?php

namespace App\Core\Exceptions;

use Exception;
use Symfony\Component\HttpFoundation\Response;

/**
 * A rule said "no", carrying the exact API envelope.
 *
 * Services decide; controllers answer. That split only works if the decision can
 * travel from one to the other without losing what it said, so a refusal brings
 * its own Arabic sentence, its machine code, its HTTP status and the `details` a
 * client switches on. Each module used to own a class of this shape and each
 * controller a method to unpack it; they were the same twelve lines written
 * under nine names, and a rule that moved between modules had to be moved twice.
 * This is the one place the envelope exists.
 *
 * Throwing rather than returning is what keeps the services honest: a rule that
 * has to hand back either a model or a response invites a caller that forgets
 * the second case, and the forgotten case is a 500 or a success message about
 * nothing. A refusal cannot be ignored, because execution stops.
 *
 * The default status is 422 because that is what almost all of these are: the
 * request was well-formed and permitted, and the data says it cannot be done.
 */
class ApiRefused extends Exception
{
    /**
     * @param  array<string, mixed>  $apiDetails
     */
    public function __construct(
        public readonly string $apiMessage,
        public readonly string $apiCode,
        public readonly int $apiStatus = Response::HTTP_UNPROCESSABLE_ENTITY,
        public readonly array $apiDetails = [],
    ) {
        parent::__construct($apiMessage);
    }
}
