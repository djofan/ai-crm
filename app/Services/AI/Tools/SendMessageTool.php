<?php

namespace App\Services\AI\Tools;

use App\Actions\Agent\SendReply;
use App\Services\AI\DTO\ToolContext;
use App\Services\AI\DTO\ToolResult;
use Illuminate\Support\Facades\Validator;

class SendMessageTool implements AgentTool
{
    public const NAME = 'send_message';

    public function __construct(private SendReply $sendReply) {}

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
                'description' => 'Kirim satu balasan teks ke pelanggan pada percakapan ini. Panggil paling banyak satu kali per giliran.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'message' => [
                            'type' => 'string',
                            'description' => 'Isi pesan untuk pelanggan, singkat dan natural untuk chat.',
                        ],
                    ],
                    'required' => ['message'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    public function execute(ToolContext $context, array $arguments): ToolResult
    {
        $validator = Validator::make($arguments, [
            'message' => ['required', 'string', 'max:'.(int) config('sales_agent.max_message_length')],
        ]);

        if ($validator->fails()) {
            return ToolResult::fail($validator->errors()->first());
        }

        $message = $this->sendReply->execute($context->conversation, $arguments['message'], $context->run);

        return ToolResult::ok("Balasan disimpan (message #{$message->id}).");
    }
}
