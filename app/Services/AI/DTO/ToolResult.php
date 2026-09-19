<?php

namespace App\Services\AI\DTO;

final readonly class ToolResult
{
    private function __construct(
        public bool $success,
        public string $output,
    ) {}

    public static function ok(string $output = 'ok'): self
    {
        return new self(true, $output);
    }

    public static function fail(string $error): self
    {
        return new self(false, $error);
    }
}
