<?php

namespace Tests\Feature\AI;

use App\Exceptions\AIServiceException;
use App\Services\AI\AIService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Concerns\FakesOpenAI;
use Tests\TestCase;

class AIServiceTest extends TestCase
{
    use FakesOpenAI;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureOpenAI();
    }

    public function test_it_sends_the_expected_request(): void
    {
        $this->fakeOpenAIToolCalls([['send_message', ['message' => 'Halo']]]);
        $tools = [['type' => 'function', 'function' => ['name' => 'send_message', 'parameters' => ['type' => 'object']]]];

        app(AIService::class)->chat(
            [['role' => 'user', 'content' => 'Hai']],
            $tools,
            ['tool_choice' => 'required', 'parallel_tool_calls' => true],
        );

        Http::assertSent(function (Request $request) use ($tools) {
            return $request->url() === 'https://api.openai.test/v1/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer test-key')
                && $request['model'] === 'test-model'
                && $request['max_completion_tokens'] === 500
                && $request['tool_choice'] === 'required'
                && $request['parallel_tool_calls'] === true
                && $request['tools'] === $tools
                && $request['messages'] === [['role' => 'user', 'content' => 'Hai']]
                && ! isset($request['temperature']);
        });
    }

    public function test_it_omits_tool_fields_when_no_tools_given(): void
    {
        Http::fake(['*' => Http::response([
            'choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => 'Halo!']]],
        ])]);

        $response = app(AIService::class)->chat([['role' => 'user', 'content' => 'Hai']]);

        $this->assertSame('Halo!', $response->content);
        $this->assertFalse($response->hasToolCalls());
        Http::assertSent(fn (Request $r) => ! isset($r['tools']) && ! isset($r['tool_choice']));
    }

    public function test_it_parses_tool_calls_usage_and_model(): void
    {
        $this->fakeOpenAIToolCalls([
            ['update_lead', ['status' => 'qualified']],
            ['send_message', ['message' => 'Halo']],
        ]);

        $response = app(AIService::class)->chat([['role' => 'user', 'content' => 'Hai']], [['type' => 'function']]);

        $this->assertCount(2, $response->toolCalls);
        $this->assertSame('update_lead', $response->toolCalls[0]->name);
        $this->assertSame(['status' => 'qualified'], $response->toolCalls[0]->arguments);
        $this->assertSame('call_1', $response->toolCalls[1]->id);
        $this->assertSame('tool_calls', $response->finishReason);
        $this->assertSame(120, $response->usage['total_tokens']);
        $this->assertSame('test-model-2026', $response->model);
    }

    public function test_invalid_tool_argument_json_yields_null_arguments(): void
    {
        $this->fakeOpenAIToolCalls([['send_message', '{oops']]);

        $response = app(AIService::class)->chat([['role' => 'user', 'content' => 'Hai']], [['type' => 'function']]);

        $this->assertNull($response->toolCalls[0]->arguments);
        $this->assertSame('{oops', $response->toolCalls[0]->rawArguments);
    }

    public function test_empty_tool_arguments_become_an_empty_array(): void
    {
        $this->fakeOpenAIToolCalls([['handoff_to_human', '']]);

        $response = app(AIService::class)->chat([['role' => 'user', 'content' => 'Hai']], [['type' => 'function']]);

        $this->assertSame([], $response->toolCalls[0]->arguments);
    }

    public function test_it_throws_when_api_key_is_missing_without_sending_a_request(): void
    {
        config(['services.openai.api_key' => null]);
        Http::fake();

        try {
            app(AIService::class)->chat([['role' => 'user', 'content' => 'Hai']]);
            $this->fail('Exception diharapkan.');
        } catch (AIServiceException $e) {
            $this->assertStringContainsString('OPENAI_API_KEY', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_it_throws_when_model_is_missing(): void
    {
        config(['services.openai.model' => null]);
        Http::fake();

        $this->expectException(AIServiceException::class);
        $this->expectExceptionMessage('OPENAI_MODEL');

        app(AIService::class)->chat([['role' => 'user', 'content' => 'Hai']]);
    }

    public function test_http_errors_become_exceptions_without_leaking_the_key(): void
    {
        Http::fake(['*' => Http::response(['error' => [
            'type' => 'invalid_request_error',
            'code' => 'invalid_api_key',
            'message' => 'Incorrect API key provided: test-key',
        ]], 401)]);

        try {
            app(AIService::class)->chat([['role' => 'user', 'content' => 'Hai']]);
            $this->fail('Exception diharapkan.');
        } catch (AIServiceException $e) {
            $this->assertStringContainsString('HTTP 401', $e->getMessage());
            $this->assertStringContainsString('invalid_api_key', $e->getMessage());
            $this->assertStringNotContainsString('test-key', $e->getMessage());
        }
    }

    public function test_it_retries_transient_server_errors(): void
    {
        Sleep::fake();
        config(['services.openai.retries' => 1]);

        Http::fake(['*' => Http::sequence()
            ->push(['error' => ['type' => 'server_error']], 500)
            ->push($this->openAIToolResponse([['send_message', ['message' => 'Halo']]]))]);

        $response = app(AIService::class)->chat([['role' => 'user', 'content' => 'Hai']], [['type' => 'function']]);

        $this->assertTrue($response->hasToolCalls());
        Http::assertSentCount(2);
    }

    public function test_it_does_not_retry_client_errors(): void
    {
        Sleep::fake();
        config(['services.openai.retries' => 2]);
        Http::fake(['*' => Http::response(['error' => ['type' => 'invalid_request_error']], 400)]);

        try {
            app(AIService::class)->chat([['role' => 'user', 'content' => 'Hai']]);
            $this->fail('Exception diharapkan.');
        } catch (AIServiceException) {
            Http::assertSentCount(1);
        }
    }

    public function test_connection_errors_are_wrapped(): void
    {
        Http::fake(fn () => throw new ConnectionException('timeout'));

        $this->expectException(AIServiceException::class);
        $this->expectExceptionMessage('Tidak dapat terhubung');

        app(AIService::class)->chat([['role' => 'user', 'content' => 'Hai']]);
    }

    public function test_malformed_response_throws(): void
    {
        Http::fake(['*' => Http::response(['unexpected' => true])]);

        $this->expectException(AIServiceException::class);
        $this->expectExceptionMessage('tidak valid');

        app(AIService::class)->chat([['role' => 'user', 'content' => 'Hai']]);
    }
}
