<?php

namespace App\Services\AI\Tools;

use App\Services\AI\DTO\ToolContext;
use App\Services\AI\DTO\ToolResult;

interface AgentTool
{
    public function name(): string;

    /**
     * Definisi tool dalam format OpenAI: {"type":"function","function":{...}}.
     *
     * @return array<string, mixed>
     */
    public function definition(): array;

    /**
     * Menjalankan tool. Tool TIDAK boleh melempar exception untuk input yang salah;
     * kembalikan ToolResult::fail() agar kegagalan tercatat tanpa menghentikan agent.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function execute(ToolContext $context, array $arguments): ToolResult;
}
