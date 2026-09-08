<?php

namespace App\Services\Teleduce;

/**
 * Simple value object for a single recipient.
 *
 * Usage:
 *   TeleduceRecipient::make('user@example.com')
 *   TeleduceRecipient::make('user@example.com', 'John Doe')
 */
class TeleduceRecipient
{
    public function __construct(
        public readonly string  $email,
        public readonly ?string $name = null,
    ) {}

    public static function make(string $email, ?string $name = null): self
    {
        return new self($email, $name);
    }

    public function toArray(): array
    {
        $data = ['email_id' => $this->email];

        if ($this->name) {
            $data['name'] = $this->name;
        }

        return $data;
    }
}