<?php

namespace App\Core;

use RuntimeException;

/** Errors the API returns on purpose. Anything else is a 500 and gets logged. */
class AppException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly mixed $details = null,
    ) {
        parent::__construct($message);
    }

    /** @return array{error: array<string, mixed>} */
    public function toBody(): array
    {
        $error = ['code' => $this->errorCode, 'message' => $this->getMessage()];
        if ($this->details !== null) {
            $error['details'] = $this->details;
        }

        return ['error' => $error];
    }
}
