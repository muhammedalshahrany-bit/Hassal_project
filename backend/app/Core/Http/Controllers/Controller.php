<?php

namespace App\Core\Http\Controllers;

use App\Core\Exceptions\ApiRefused;
use App\Core\Http\ApiResponse;
use Illuminate\Http\JsonResponse;

abstract class Controller
{
    /**
     * Render a refusal a service decided.
     *
     * Every endpoint that calls a rule-bearing service needs this, and every one
     * of them needs it to do the same thing: say exactly what the service said,
     * under the code the client was promised, at the status the contract fixes.
     * A per-controller copy is how one endpoint starts answering a rule with a
     * generic 422 while its neighbour names the code.
     */
    protected function refused(ApiRefused $refused): JsonResponse
    {
        return ApiResponse::error(
            $refused->apiMessage,
            $refused->apiCode,
            $refused->apiStatus,
            $refused->apiDetails !== [] ? $refused->apiDetails : null
        );
    }
}
