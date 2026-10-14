<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Tests\Feature\Console;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Nabafzar\LogCenter\Tests\TestCase;

final class CheckCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('logcenter.default', 'telegram');
        config()->set('logcenter.drivers.telegram.token', 'TEST_TOKEN');
        config()->set('logcenter.drivers.telegram.chat_id', '-100123');
    }

    public function test_a_valid_telegram_setup_passes(): void
    {
        Http::fake([
            'https://api.telegram.org/botTEST_TOKEN/getMe' => Http::response(['ok' => true, 'result' => ['username' => 'alerts_bot']]),
            'https://api.telegram.org/botTEST_TOKEN/getChat*' => Http::response(['ok' => true, 'result' => ['id' => -100123]]),
        ]);

        $this->assertSame(0, $this->check());
        $this->assertStringContainsString('Token valid (@alerts_bot)', $this->output);
        $this->assertStringContainsString('Bot can reach chat -100123', $this->output);

        // Read-only calls: nothing is ever sent to the chat.
        Http::assertNotSent(static fn ($request): bool => str_contains($request->url(), 'sendMessage'));
    }

    public function test_a_rejected_token_fails(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Unauthorized'], 401),
        ]);

        $this->assertSame(1, $this->check());
        $this->assertStringContainsString('Token rejected: Unauthorized', $this->output);
    }

    public function test_a_chat_the_bot_cannot_reach_fails(): void
    {
        Http::fake([
            'https://api.telegram.org/botTEST_TOKEN/getMe' => Http::response(['ok' => true, 'result' => ['username' => 'alerts_bot']]),
            'https://api.telegram.org/botTEST_TOKEN/getChat*' => Http::response(['ok' => false, 'description' => 'Bad Request: chat not found'], 400),
        ]);

        $this->assertSame(1, $this->check());
        $this->assertStringContainsString('Cannot reach chat -100123: Bad Request: chat not found', $this->output);
    }

    public function test_a_connection_error_does_not_print_the_token(): void
    {
        Http::fake(static fn ($request) => throw new ConnectionException('cURL error 28: timed out for '.$request->url()));

        $this->assertSame(1, $this->check());
        $this->assertStringContainsString('bot******/getMe', $this->output);
        $this->assertStringNotContainsString('TEST_TOKEN', $this->output);
    }

    public function test_missing_credentials_fail_without_a_request(): void
    {
        config()->set('logcenter.drivers.telegram.token', null);
        Http::fake();

        $this->assertSame(1, $this->check());
        $this->assertStringContainsString('LOGCENTER_TELEGRAM_TOKEN and LOGCENTER_TELEGRAM_CHAT_ID are required', $this->output);
        Http::assertNothingSent();
    }

    public function test_offline_skips_live_calls(): void
    {
        Http::fake();

        $this->assertSame(0, $this->check(['--offline' => true]));
        $this->assertStringContainsString('Skipped (--offline)', $this->output);
        Http::assertNothingSent();
    }

    public function test_an_unknown_driver_fails(): void
    {
        config()->set('logcenter.default', 'slack');

        $this->assertSame(1, $this->check(['--offline' => true]));
        $this->assertStringContainsString('Unsupported log transport [slack]', $this->output);
    }

    public function test_a_fallback_equal_to_the_default_is_a_warning(): void
    {
        config()->set('logcenter.fallback', 'Telegram');

        $this->assertSame(0, $this->check(['--offline' => true]));
        $this->assertStringContainsString('Same as the default driver (telegram); it is never used', $this->output);
    }

    public function test_an_invalid_level_fails(): void
    {
        config()->set('logcenter.level', 'urgent');

        $this->assertSame(1, $this->check(['--offline' => true]));
        $this->assertStringContainsString('LOGCENTER_LEVEL=urgent is not a log level', $this->output);
    }

    public function test_the_failure_channel_must_exist(): void
    {
        config()->set('logcenter.failure_channel', 'missing');
        $this->assertSame(1, $this->check(['--offline' => true]));
        $this->assertStringContainsString('Channel [missing] is not defined', $this->output);

        config()->set('logcenter.failure_channel', 'single');
        $this->assertSame(0, $this->check(['--offline' => true]));
        $this->assertStringContainsString('Channel [single] exists', $this->output);
    }

    public function test_an_incoming_endpoint_without_a_token_fails(): void
    {
        config()->set('logcenter.incoming.auth.token', null);

        $this->assertSame(1, $this->check(['--offline' => true]));
        $this->assertStringContainsString('LOGCENTER_INCOMING_TOKEN is empty', $this->output);
    }

    public function test_an_unreachable_queue_fails(): void
    {
        config()->set('queue.connections.unavailable', ['driver' => 'redis', 'connection' => 'missing', 'queue' => 'logs']);
        config()->set('logcenter.queue.enabled', true);
        config()->set('logcenter.queue.connection', 'unavailable');

        $this->assertSame(1, $this->check(['--offline' => true]));
        $this->assertStringContainsString('Connection [unavailable] unavailable', $this->output);
    }

    public function test_bale_uses_its_own_base_url(): void
    {
        config()->set('logcenter.drivers.bale.token', 'BALE_TOKEN');
        config()->set('logcenter.drivers.bale.chat_id', '42');
        Http::fake([
            'https://tapi.bale.ai/botBALE_TOKEN/getMe' => Http::response(['ok' => true, 'result' => ['username' => 'bale_bot']]),
            'https://tapi.bale.ai/botBALE_TOKEN/getChat*' => Http::response(['ok' => true, 'result' => ['id' => 42]]),
        ]);

        $this->assertSame(0, $this->check(['--driver' => 'bale']));
        $this->assertStringContainsString('Bale bot', $this->output);
        $this->assertStringContainsString('Token valid (@bale_bot)', $this->output);
    }

    public function test_a_mattermost_bot_token_is_checked_with_users_me(): void
    {
        config()->set('logcenter.drivers.mattermost.bot_token', 'MM_TOKEN');
        config()->set('logcenter.drivers.mattermost.base_url', 'https://chat.example.com');
        config()->set('logcenter.drivers.mattermost.channel_id', 'abc');
        Http::fake(['https://chat.example.com/api/v4/users/me' => Http::response(['username' => 'logbot'])]);

        $this->assertSame(0, $this->check(['--driver' => 'mattermost']));
        $this->assertStringContainsString('Token valid (@logbot)', $this->output);
        Http::assertSent(static fn ($request): bool => $request->hasHeader('Authorization', 'Bearer MM_TOKEN'));
    }

    private string $output = '';

    /**
     * @param  array<string, mixed>  $options
     */
    private function check(array $options = []): int
    {
        $exitCode = Artisan::call('logcenter:check', $options);
        $this->output = Artisan::output();

        return $exitCode;
    }
}
