<?php

namespace App\Services\AI\Tools;

use App\Actions\Agent\ScheduleFollowUp;
use App\Services\AI\DTO\ToolContext;
use App\Services\AI\DTO\ToolResult;
use Illuminate\Support\Facades\Validator;

class ScheduleFollowUpTool implements AgentTool
{
    public const NAME = 'schedule_follow_up';

    public const MAX_HOURS = 720;

    public function __construct(private ScheduleFollowUp $scheduleFollowUp) {}

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
                'description' => 'Jadwalkan follow-up ke pelanggan beberapa jam dari sekarang, misalnya jika pelanggan belum memutuskan.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'hours' => [
                            'type' => 'integer',
                            'minimum' => 1,
                            'maximum' => self::MAX_HOURS,
                            'description' => 'Berapa jam dari sekarang follow-up dilakukan.',
                        ],
                        'reason' => ['type' => 'string', 'description' => 'Alasan singkat follow-up.'],
                    ],
                    'required' => ['hours'],
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
            return ToolResult::fail('Lead sudah ditutup; follow-up tidak dijadwalkan.');
        }

        $validator = Validator::make($arguments, [
            'hours' => ['required', 'integer', 'min:1', 'max:'.self::MAX_HOURS],
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return ToolResult::fail($validator->errors()->first());
        }

        $data = $validator->validated();
        $lead = $this->scheduleFollowUp->execute($lead, (int) $data['hours'], $data['reason'] ?? null);

        return ToolResult::ok('Follow-up dijadwalkan pada '.$lead->next_follow_up_at->toIso8601String().'.');
    }
}
