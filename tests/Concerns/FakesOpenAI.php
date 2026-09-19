<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Http;

trait FakesOpenAI
{
    protected function configureOpenAI(): void
    {
        config([
            'services.openai.api_key' => 'test-key',
            'services.openai.model' => 'test-model',
            'services.openai.base_url' => 'https://api.openai.test/v1',
            'services.openai.retries' => 0,
            'services.openai.max_output_tokens' => 500,
        ]);
    }

    /**
     * @param  list<array{0: string, 1: array<string, mixed>|string}>  $calls  [nama_tool, argumen] (argumen string = JSON mentah)
     * @return array<string, mixed>
     */
    protected function openAIToolResponse(array $calls): array
    {
        $toolCalls = [];

        foreach (array_values($calls) as $i => [$name, $arguments]) {
            $toolCalls[] = [
                'id' => 'call_'.$i,
                'type' => 'function',
                'function' => [
                    'name' => $name,
                    'arguments' => is_string($arguments) ? $arguments : json_encode($arguments),
                ],
            ];
        }

        return [
            'id' => 'chatcmpl-test',
            'model' => 'test-model-2026',
            'choices' => [[
                'index' => 0,
                'finish_reason' => 'tool_calls',
                'message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => $toolCalls],
            ]],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20, 'total_tokens' => 120],
        ];
    }

    /**
     * @param  list<array{0: string, 1: array<string, mixed>|string}>  $calls
     */
    protected function fakeOpenAIToolCalls(array $calls): void
    {
        Http::fake(['api.openai.test/*' => Http::response($this->openAIToolResponse($calls))]);
    }
}
