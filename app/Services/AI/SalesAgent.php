<?php

namespace App\Services\AI;

use App\Enums\AgentRunStatus;
use App\Exceptions\AIServiceException;
use App\Models\AgentRun;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\AI\DTO\AIResponse;
use App\Services\AI\DTO\ToolContext;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Tools\HandoffToHumanTool;
use App\Services\AI\Tools\SendMessageTool;
use App\Services\AI\Tools\ToolRegistry;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * AI Sales Agent: membaca konteks percakapan, meminta keputusan ke model (tool calling),
 * lalu menjalankan tool yang dipilih lewat application service.
 *
 * Satu giliran = satu panggilan ke model. Hasil tool tidak dikirim balik ke model.
 * Agent tidak tahu apa pun soal WhatsApp/provider; ia hanya memanggil tool.
 */
class SalesAgent
{
    /** Run berstatus "running" lebih lama dari ini dianggap macet dan boleh diambil ulang. */
    private const STALE_RUN_MINUTES = 5;

    public function __construct(
        private AIService $ai,
        private PromptBuilder $prompts,
        private ToolRegistry $tools,
    ) {}

    /**
     * Memproses percakapan. Mengembalikan:
     * - null jika AI dinonaktifkan untuk percakapan ini (tidak ada yang dijalankan);
     * - AgentRun yang sudah ada jika pesan pemicu ini sudah/sedang diproses (idempotent);
     * - AgentRun baru (completed atau failed) setelah agent dijalankan.
     */
    public function handle(Conversation $conversation, ?Message $trigger = null): ?AgentRun
    {
        if (! $conversation->ai_enabled) {
            return null;
        }

        [$run, $shouldRun] = $this->claimRun($conversation, $trigger);

        if (! $shouldRun) {
            return $run;
        }

        try {
            $response = $this->ai->chat(
                $this->prompts->build($conversation),
                $this->tools->definitions(),
                ['tool_choice' => 'required', 'parallel_tool_calls' => true],
            );

            if (! $response->hasToolCalls()) {
                return $this->fail($run, 'Model tidak memanggil tool apa pun (finish_reason='.($response->finishReason ?? 'null').').', $response);
            }

            $context = new ToolContext(
                conversation: $conversation,
                customer: $conversation->customer,
                lead: $conversation->lead,
                triggerMessage: $trigger,
                run: $run,
            );

            $actions = $this->execute($response, $context);
        } catch (AIServiceException $e) {
            Log::warning('AI Sales Agent gagal memanggil model.', [
                'conversation_id' => $conversation->id,
                'agent_run_id' => $run->id,
                'error' => $e->getMessage(),
            ]);

            return $this->fail($run, $e->getMessage());
        } catch (Throwable $e) {
            $this->fail($run, 'Kesalahan internal: '.$e::class);

            throw $e;
        }

        // Giliran dianggap berhasil hanya jika pelanggan mendapat balasan atau percakapan diserahkan ke manusia.
        $produced = collect($actions)->contains(
            fn (array $a) => $a['success'] && in_array($a['tool'], [SendMessageTool::NAME, HandoffToHumanTool::NAME], true)
        );

        $run->update([
            'model' => $response->model,
            'status' => $produced ? AgentRunStatus::Completed : AgentRunStatus::Failed,
            'actions' => $actions,
            'usage' => $response->usage,
            'error' => $produced ? null : 'Agent tidak menghasilkan balasan atau handoff yang valid.',
            'finished_at' => now(),
        ]);

        return $run;
    }

    /**
     * Menjalankan tool call sesuai urutan registry. Kegagalan satu tool tidak menghentikan yang lain.
     *
     * @return list<array{tool: string, arguments: array<string, mixed>|null, success: bool, output: string}>
     */
    private function execute(AIResponse $response, ToolContext $context): array
    {
        $calls = $response->toolCalls;
        usort($calls, fn ($a, $b) => $this->tools->order($a->name) <=> $this->tools->order($b->name));

        $actions = [];
        $replied = false;

        foreach ($calls as $call) {
            $tool = $this->tools->find($call->name);

            if ($tool === null) {
                $result = ToolResult::fail("Tool tidak dikenal: {$call->name}");
            } elseif ($call->arguments === null) {
                $result = ToolResult::fail('Argumen bukan JSON object yang valid.');
            } elseif ($call->name === SendMessageTool::NAME && $replied) {
                $result = ToolResult::fail('send_message hanya boleh dipanggil sekali per giliran.');
            } else {
                try {
                    $result = $tool->execute($context, $call->arguments);
                } catch (Throwable $e) {
                    report($e);
                    $result = ToolResult::fail('Kesalahan internal saat menjalankan tool.');
                }

                if ($call->name === SendMessageTool::NAME && $result->success) {
                    $replied = true;
                }
            }

            $actions[] = [
                'tool' => $call->name,
                'arguments' => $call->arguments,
                'success' => $result->success,
                'output' => $result->output,
            ];
        }

        return $actions;
    }

    /**
     * Mengklaim hak memproses pesan pemicu. Unique index pada trigger_message_id membuat
     * hanya satu worker yang bisa membuat run untuk pesan yang sama.
     *
     * @return array{0: AgentRun, 1: bool}
     */
    private function claimRun(Conversation $conversation, ?Message $trigger): array
    {
        $values = [
            'conversation_id' => $conversation->id,
            'status' => AgentRunStatus::Running,
            'started_at' => now(),
        ];

        if ($trigger === null) {
            return [AgentRun::create($values), true];
        }

        $run = AgentRun::createOrFirst(['trigger_message_id' => $trigger->id], $values);

        if ($run->wasRecentlyCreated) {
            return [$run, true];
        }

        // Run lama boleh diulang hanya jika gagal, atau "running" tapi sudah macet.
        $reclaimed = AgentRun::query()
            ->whereKey($run->id)
            ->where(function ($query) {
                $query->where('status', AgentRunStatus::Failed->value)
                    ->orWhere(function ($query) {
                        $query->where('status', AgentRunStatus::Running->value)
                            ->where('updated_at', '<', now()->subMinutes(self::STALE_RUN_MINUTES));
                    });
            })
            ->update([
                'status' => AgentRunStatus::Running->value,
                'error' => null,
                'started_at' => now(),
                'finished_at' => null,
                'updated_at' => now(),
            ]);

        $run->refresh();

        return [$run, $reclaimed === 1];
    }

    private function fail(AgentRun $run, string $error, ?AIResponse $response = null): AgentRun
    {
        $run->update([
            'model' => $response?->model,
            'status' => AgentRunStatus::Failed,
            'usage' => $response?->usage,
            'error' => $error,
            'finished_at' => now(),
        ]);

        return $run;
    }
}
