<?php

declare(strict_types=1);

namespace App\Domain\Lending\Bre\Dto;

final class CompiledRuleSet
{
    /** @param array<string,array> $byType rule_type => sorted rules[] */
    public function __construct(
        public readonly array $byType,
        public readonly ?int $defaultSetId,
        public readonly ?int $dealerSetId,
    ) {}

    public function rules(string $type): array
    {
        return $this->byType[$type] ?? [];
    }
}
