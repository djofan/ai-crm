<?php

namespace App\Services\AI\DTO;

final readonly class AIResponse
{
    /**
     * @param  list<ToolCall>  $toolCalls
     * @param  array<string, mixed>  $usage
     */
    public function __construct(
        public ?string $content,
        public array $toolCalls,
        public ?string $finishReason,
        public array $usage,
        public ?string $model,
    ) {}

    public function hasToolCalls(): bool
    {
        return $this->toolCalls !== [];
    }
}
