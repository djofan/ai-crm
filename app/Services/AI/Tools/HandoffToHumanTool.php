<?php

namespace App\Services\AI\Tools;

use App\Actions\Agent\HandoffToHuman;
use App\Services\AI\DTO\ToolContext;
use App\Services\AI\DTO\ToolResult;
use Illuminate\Support\Facades\Validator;

class HandoffToHumanTool implements AgentTool
{
    public const NAME = 'handoff_to_human';

    public function __construct(private HandoffToHuman $handoff) {}

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
                'description' => 'Serahkan percakapan ke tim manusia dan hentikan balasan otomatis. Gunakan jika pelanggan meminta manusia, komplain, soal pembayaran/hukum, atau kamu tidak yakin jawabannya.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'reason' => ['type' => 'string', 'description' => 'Alasan singkat penyerahan.'],
                    ],
                    'required' => ['reason'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    public function execute(ToolContext $context, array $arguments): ToolResult
    {
        $validator = Validator::make($arguments, [
            'reason' => ['required', 'string', 'max:500'],
        ]);

        if ($validator->fails()) {
            return ToolResult::fail($validator->errors()->first());
        }

        $this->handoff->execute($context->conversation, $arguments['reason']);

        return ToolResult::ok('Percakapan diserahkan ke tim manusia; AI dinonaktifkan untuk percakapan ini.');
    }
}
