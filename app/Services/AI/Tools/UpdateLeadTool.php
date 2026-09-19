<?php

namespace App\Services\AI\Tools;

use App\Actions\Agent\UpdateLeadQualification;
use App\Enums\LeadStatus;
use App\Services\AI\DTO\ToolContext;
use App\Services\AI\DTO\ToolResult;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class UpdateLeadTool implements AgentTool
{
    public const NAME = 'update_lead';

    public function __construct(private UpdateLeadQualification $updateLead) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => self::NAME,
                'description' => 'Perbarui data kualifikasi lead berdasarkan informasi baru dari pelanggan. Isi hanya field yang benar-benar diketahui.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'status' => [
                            'type' => 'string',
                            'enum' => array_map(fn (LeadStatus $s) => $s->value, LeadStatus::agentAssignable()),
                            'description' => 'Status lead terbaru. Status won hanya boleh ditetapkan manusia.',
                        ],
                        'interest' => ['type' => 'string', 'description' => 'Produk/layanan yang diminati pelanggan.'],
                        'estimated_value' => ['type' => 'number', 'description' => 'Perkiraan nilai deal (Rupiah), hanya jika pelanggan menyebutkannya.'],
                        'notes' => ['type' => 'string', 'description' => 'Catatan singkat yang berguna untuk tim sales.'],
                    ],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    public function execute(ToolContext $context, array $arguments): ToolResult
    {
        $lead = $context->lead;

        if ($lead === null) {
            return ToolResult::fail('Percakapan ini belum terhubung ke lead.');
        }

        if (! $lead->status->isOpen()) {
            return ToolResult::fail('Lead sudah ditutup; tidak dapat diubah oleh agent.');
        }

        $validator = Validator::make($arguments, [
            'status' => ['sometimes', 'string', Rule::in(array_map(fn (LeadStatus $s) => $s->value, LeadStatus::agentAssignable()))],
            'interest' => ['sometimes', 'string', 'max:255'],
            'estimated_value' => ['sometimes', 'numeric', 'min:0', 'max:999999999999'],
            'notes' => ['sometimes', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return ToolResult::fail($validator->errors()->first());
        }

        $data = array_filter(
            $validator->validated(),
            fn ($value) => $value !== null && trim((string) $value) !== '',
        );

        if ($data === []) {
            return ToolResult::fail('Tidak ada field yang diisi.');
        }

        $this->updateLead->execute($lead, $data);

        return ToolResult::ok('Lead diperbarui: '.implode(', ', array_keys($data)).'.');
    }
}
