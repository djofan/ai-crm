<?php

namespace App\Services\AI\Tools;

/**
 * Daftar tool yang tersedia untuk agent. Urutan pendaftaran = urutan eksekusi:
 * perubahan state (lead, follow-up, handoff) dijalankan lebih dulu, balasan terakhir.
 */
class ToolRegistry
{
    /** @var array<string, AgentTool> */
    private array $tools = [];

    /** @var array<string, int> */
    private array $order = [];

    /**
     * @param  list<AgentTool>  $tools
     */
    public function __construct(array $tools)
    {
        foreach (array_values($tools) as $index => $tool) {
            $this->tools[$tool->name()] = $tool;
            $this->order[$tool->name()] = $index;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function definitions(): array
    {
        return array_values(array_map(fn (AgentTool $tool) => $tool->definition(), $this->tools));
    }

    public function find(string $name): ?AgentTool
    {
        return $this->tools[$name] ?? null;
    }

    public function order(string $name): int
    {
        return $this->order[$name] ?? PHP_INT_MAX;
    }
}
