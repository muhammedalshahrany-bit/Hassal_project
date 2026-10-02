<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown when stock cannot be held because the offer's own validity has ended.
 *
 * Carries the offer id so the caller can persist the state change *after* the
 * reservation transaction has been rolled back. Writing the status inside that
 * transaction and then throwing silently discarded the correction, leaving an
 * expired offer looking reservable in the catalogue.
 */
class OfferExpiredException extends Exception
{
    public function __construct(
        public readonly int $offerId,
        string $message = 'The offer has already expired.'
    ) {
        parent::__construct($message);
    }
}
