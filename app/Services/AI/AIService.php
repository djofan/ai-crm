<?php

namespace App\Services\AI;

use App\Exceptions\AIServiceException;
use App\Services\AI\DTO\AIResponse;
use App\Services\AI\DTO\ToolCall;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Klien tipis untuk OpenAI Chat Completions (dengan tool calling).
 *
 * Sengaja tanpa SDK: lebih sedikit dependency untuk shared hosting dan mudah di-fake
 * di test lewat Http::fake(). Service ini tidak tahu apa pun soal CRM atau WhatsApp.
 */
class AIService
{
    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array<string, mixed>>  $tools  definisi tool format OpenAI ({"type":"function",...})
     * @param  array{model?: string, tool_choice?: string|array<string, mixed>, parallel_tool_calls?: bool, temperature?: float, max_output_tokens?: int}  $options
     *
     * @throws AIServiceException
     */
    public function chat(array $messages, array $tools = [], array $options = []): AIResponse
    {
        $apiKey = config('services.openai.api_key');
        $model = $options['model'] ?? config('services.openai.model');

        if (blank($apiKey)) {
            throw AIServiceException::notConfigured('OPENAI_API_KEY');
        }

        if (blank($model)) {
            throw AIServiceException::notConfigured('OPENAI_MODEL');
        }

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'max_completion_tokens' => $options['max_output_tokens'] ?? (int) config('services.openai.max_output_tokens'),
        ];

        if ($tools !== []) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = $options['tool_choice'] ?? 'auto';

            if (isset($options['parallel_tool_calls'])) {
                $payload['parallel_tool_calls'] = (bool) $options['parallel_tool_calls'];
            }
        }

        if (isset($options['temperature'])) {
            $payload['temperature'] = $options['temperature'];
        }

        try {
            $response = $this->request((string) $apiKey)->post('/chat/completions', $payload);
        } catch (ConnectionException $e) {
            throw AIServiceException::connectionFailed($e->getMessage());
        }

        if ($response->failed()) {
            throw AIServiceException::requestFailed(
                $response->status(),
                $response->json('error.type'),
                $response->json('error.code'),
            );
        }

        return $this->parse($response->json());
    }

    private function request(string $apiKey): PendingRequest
    {
        $request = Http::baseUrl(rtrim((string) config('services.openai.base_url'), '/'))
            ->withToken($apiKey)
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('services.openai.timeout'));

        $retries = max(0, (int) config('services.openai.retries'));

        if ($retries > 0) {
            $request = $request->retry(
                $retries + 1,
                500,
                fn (Throwable $e) => $this->shouldRetry($e),
                throw: false,
            );
        }

        return $request;
    }

    private function shouldRetry(Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        return $e instanceof RequestException
            && in_array($e->response->status(), [429, 500, 502, 503, 504], true);
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    private function parse(?array $data): AIResponse
    {
        $choice = $data['choices'][0] ?? null;

        if (! is_array($choice) || ! isset($choice['message']) || ! is_array($choice['message'])) {
            throw AIServiceException::malformedResponse('choices[0].message tidak ada');
        }

        $message = $choice['message'];
        $toolCalls = [];

        foreach ($message['tool_calls'] ?? [] as $call) {
            if (($call['type'] ?? 'function') !== 'function' || ! isset($call['function']['name'])) {
                continue;
            }

            $raw = (string) ($call['function']['arguments'] ?? '');
            $arguments = $raw === '' ? [] : json_decode($raw, true);

            $toolCalls[] = new ToolCall(
                id: (string) ($call['id'] ?? ''),
                name: (string) $call['function']['name'],
                arguments: is_array($arguments) ? $arguments : null,
                rawArguments: $raw,
            );
        }

        return new AIResponse(
            content: isset($message['content']) ? (string) $message['content'] : null,
            toolCalls: $toolCalls,
            finishReason: $choice['finish_reason'] ?? null,
            usage: is_array($data['usage'] ?? null) ? $data['usage'] : [],
            model: $data['model'] ?? null,
        );
    }
}
