<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ApiResponse
{
    public static function success(
        mixed $data = null,
        string $message = 'Operation completed successfully',
        int $status = Response::HTTP_OK,
        array $headers = []
    ): JsonResponse {
        $correlationId = request()->header('X-Correlation-Id', (string) Str::uuid());

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'correlation_id' => $correlationId,
            ],
        ], $status, $headers);
    }

    public static function paginated(
        LengthAwarePaginator $paginator,
        string $message = 'Data retrieved successfully',
        int $status = Response::HTTP_OK
    ): JsonResponse {
        $correlationId = request()->header('X-Correlation-Id', (string) Str::uuid());

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total_items' => $paginator->total(),
                'total_pages' => $paginator->lastPage(),
                'has_more' => $paginator->hasMorePages(),
            ],
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'correlation_id' => $correlationId,
            ],
        ], $status);
    }

    public static function error(
        string $message,
        string $code = 'BAD_REQUEST',
        int $status = Response::HTTP_BAD_REQUEST,
        array|null $details = null,
        array $headers = []
    ): JsonResponse {
        $correlationId = request()->header('X-Correlation-Id', (string) Str::uuid());

        return response()->json([
            'success' => false,
            'error_code' => $code,
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details ?? [],
            ],
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'correlation_id' => $correlationId,
            ],
        ], $status, $headers);
    }
}
