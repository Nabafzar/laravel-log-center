<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Tests\Unit\Viewer;

use Nabafzar\LogCenter\Tests\TestCase;
use Nabafzar\LogCenter\Viewer\AiPrompt;

final class AiPromptTest extends TestCase
{
    public function test_it_describes_the_error_with_source_trace_and_context(): void
    {
        $file = base_path('app/Http/Controllers/OrderController.php');
        $prompt = (new AiPrompt)->build([
            'level' => 'error',
            'channel' => 'local',
            'count' => 3,
            'first_timestamp' => '2026-05-08 09:00:00',
            'last_timestamp' => '2026-05-08 10:00:00',
            'exception' => ['class' => 'RuntimeException', 'message' => 'Order total is negative', 'file' => $file, 'line' => 42],
            'snippet' => ['start' => 41, 'line' => 42, 'lines' => [41 => '$total = $order->total();', 42 => 'throw new RuntimeException();']],
            'latest' => [
                'stack' => "#0 {$file}(42): App\\Http\\Controllers\\OrderController->store()\n#1 {main}",
                'json_context' => ['order_id' => 7],
                'previous_exceptions' => [['class' => 'LogicException', 'message' => 'Bad price', 'file' => $file, 'line' => 30]],
            ],
        ]);

        $this->assertStringContainsString('Explain the most likely root cause', $prompt);
        $this->assertStringContainsString('Exception: RuntimeException', $prompt);
        $this->assertStringContainsString('Message: Order total is negative', $prompt);
        $this->assertStringContainsString('Location: app/Http/Controllers/OrderController.php:42', $prompt);
        $this->assertStringContainsString('Occurrences: 3 (first seen 2026-05-08 09:00:00, last seen 2026-05-08 10:00:00)', $prompt);
        $this->assertStringContainsString('- LogicException: Bad price (app/Http/Controllers/OrderController.php:30)', $prompt);
        $this->assertStringContainsString('>42 | throw new RuntimeException();', $prompt);
        $this->assertStringContainsString("## Stack trace\n```\n#0 app/Http/Controllers/OrderController.php(42)", $prompt);
        $this->assertStringContainsString('"order_id": 7', $prompt);
        $this->assertStringNotContainsString(base_path().'/', $prompt);
    }

    public function test_it_masks_secrets_before_the_prompt_leaves_the_server(): void
    {
        $prompt = (new AiPrompt)->build([
            'level' => 'error',
            'message' => 'Login failed password=hunter2',
            'latest' => [
                'stack' => '#0 Client->send(Authorization: Bearer abc.def.ghi)',
                'json_context' => ['user' => ['email' => 'a@example.com', 'api_token' => ['nested-secret']]],
            ],
        ]);

        $this->assertStringContainsString('password=******', $prompt);
        $this->assertStringContainsString('Bearer ******', $prompt);
        $this->assertStringContainsString('"api_token": "******"', $prompt);
        $this->assertStringContainsString('a@example.com', $prompt);
        foreach (['hunter2', 'abc.def.ghi', 'nested-secret'] as $secret) {
            $this->assertStringNotContainsString($secret, $prompt);
        }
    }

    public function test_backticks_in_the_trace_cannot_close_the_code_block(): void
    {
        $prompt = (new AiPrompt)->build([
            'message' => 'Broken',
            'latest' => ['stack' => "#0 render(```)\n#1 {main}"],
        ]);

        $this->assertStringContainsString("````\n#0 render(```)\n#1 {main}\n````", $prompt);
    }

    public function test_a_long_trace_is_truncated(): void
    {
        $prompt = (new AiPrompt)->build([
            'message' => 'Broken',
            'latest' => ['stack' => str_repeat("#0 frame\n", 5000)],
        ]);

        $this->assertStringContainsString('... [truncated]', $prompt);
        $this->assertLessThan(15000, strlen($prompt));
    }
}
