<?php

namespace App\Api;

use Exception;
use Illuminate\Http\JsonResponse;

/**
 * API error with the uniform envelope {error: {code, message, details?}}.
 * Codes are readable slugs; the legacy E00–E31 taxonomy (docs/02) informs
 * the granularity but not the wire format.
 */
class ApiException extends Exception
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly ?array $details = null,
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        $error = ['code' => $this->errorCode, 'message' => $this->getMessage()];
        if ($this->details !== null) {
            $error['details'] = $this->details;
        }

        return response()->json(['error' => $error], $this->status);
    }
}
