<?php

namespace App\Services;

class SmsResponse
{
    public function __construct(
        public bool $success,
        public ?string $httpCode = null,
        public ?string $messageId = null,
        public ?string $providerMessage = null,
        public ?string $creditsUsed = null,
        public ?string $raw = null,
        public ?string $error = null
    ) {}

    public static function failed(string $error, ?int $httpCode = null): self
    {
        return new self(
            success: false,
            httpCode: $httpCode,
            error: $error
        );
    }
}
