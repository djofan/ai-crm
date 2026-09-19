<?php

namespace App\Services\AI\DTO;

final readonly class ToolCall
{
    /**
     * @param  array<string, mixed>|null  $arguments  null jika argumen dari model bukan JSON object yang valid
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?array $arguments,
        public string $rawArguments = '',
    ) {}
}
