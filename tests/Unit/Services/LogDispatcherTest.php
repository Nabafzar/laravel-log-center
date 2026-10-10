<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Tests\Unit\Services;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Nabafzar\LogCenter\Contracts\LogFormatterInterface;
use Nabafzar\LogCenter\Contracts\LogTransportInterface;
use Nabafzar\LogCenter\DTO\FormattedMessage;
use Nabafzar\LogCenter\DTO\LogPayload;
use Nabafzar\LogCenter\DTO\TransportResult;
use Nabafzar\LogCenter\Jobs\DispatchLogJob;
use Nabafzar\LogCenter\Managers\TransportManager;
use Nabafzar\LogCenter\Processors\PayloadProcessor;
use Nabafzar\LogCenter\Services\LogDispatcher;
use Nabafzar\LogCenter\Suppression\SuppressionManager;
use Nabafzar\LogCenter\Tests\TestCase;
use RuntimeException;

final class LogDispatcherTest extends TestCase
{
    public function test_it_sends_now_through_the_resolved_transport(): void
    {
        $payload = $this->makePayload([
            'message' => 'password=secret',
            'context' => ['password' => 'secret'],
        ]);

        $captures = (object) [
            'processedPayload' => null,
            'formattedPayload' => null,
        ];

        $formatter = new class($captures) implements LogFormatterInterface
        {
            public function __construct(
                private object $captures,
            ) {}

            public function format(LogPayload $payload): FormattedMessage
            {
                $this->captures->formattedPayload = $payload;

                return new FormattedMessage('formatted message');
            }
        };

        $transport = new class($captures) implements LogTransportInterface
        {
            public function __construct(
                private object $captures,
            ) {}

            public function send(LogPayload $payload, FormattedMessage $message): TransportResult
            {
                $this->captures->processedPayload = $payload;

                return TransportResult::success('null', meta: [
                    'message' => $message->content,
                ]);
            }

            public function supportedFormats(): array
            {
                return [];
            }

            public function supportsTopics(): bool
            {
                return false;
            }
        };

        $manager = $this->createMock(TransportManager::class);
        $manager->expects($this->once())
            ->method('formatter')
            ->with('plain', 'telegram')
            ->willReturn($formatter);

        $manager->expects($this->once())
            ->method('driver')
            ->with('telegram')
            ->willReturn($transport);

        $manager->method('defaultDriverName')->willReturn('telegram');

        $dispatcher = new LogDispatcher($manager, new PayloadProcessor, new SuppressionManager);
        $result = $dispatcher->sendNow($payload, 'telegram', 'plain');

        $this->assertTrue($result->isSuccess());
        $this->assertSame('formatted message', $result->meta['message']);
        $this->assertInstanceOf(LogPayload::class, $captures->processedPayload);
        $this->assertInstanceOf(LogPayload::class, $captures->formattedPayload);
        $this->assertStringContainsString('password=******', $captures->processedPayload->message);
        $this->assertSame('******', $captures->formattedPayload->context['password']);
    }

    public function test_it_queues_a_job_when_queueing_is_enabled(): void
    {
        Bus::fake();
        config()->set('logcenter.queue.enabled', true);
        config()->set('logcenter.queue.connection', 'redis');
        config()->set('logcenter.queue.queue', 'logs');
        config()->set('logcenter.default', 'telegram');
        config()->set('logcenter.drivers.telegram.formatter', 'telegram_html');

        $manager = $this->createMock(TransportManager::class);
        $manager->method('defaultDriverName')->willReturn('telegram');
        $manager->method('formatterName')->willReturn('telegram_html');
        $manager->expects($this->never())->method('driver');

        $dispatcher = new LogDispatcher($manager, new PayloadProcessor, new SuppressionManager);
        $result = $dispatcher->dispatch($this->makePayload());

        $this->assertTrue($result->isSuccess());
        $this->assertTrue($result->meta['queued']);
        $this->assertSame('telegram_html', $result->meta['formatter']);
        $this->assertSame('redis', $result->meta['connection']);
        $this->assertSame('logs', $result->meta['queue']);

        Bus::assertDispatched(DispatchLogJob::class, static function (DispatchLogJob $job): bool {
            return $job->driver === 'telegram'
                && $job->formatter === 'telegram_html';
        });
    }

    public function test_it_masks_the_payload_before_queueing_so_secrets_never_reach_the_queue(): void
    {
        Bus::fake();
        config()->set('logcenter.queue.enabled', true);
        config()->set('logcenter.default', 'telegram');

        $manager = $this->createStub(TransportManager::class);
        $manager->method('defaultDriverName')->willReturn('telegram');
        $manager->method('formatterName')->willReturn('telegram_html');

        $dispatcher = new LogDispatcher($manager, new PayloadProcessor, new SuppressionManager);
        $dispatcher->dispatch($this->makePayload([
            'message' => 'login password=hunter2',
            'context' => ['password' => 'hunter2'],
        ]));

        Bus::assertDispatched(DispatchLogJob::class, static function (DispatchLogJob $job): bool {
            return str_contains($job->payload->message, 'password=******')
                && ! str_contains($job->payload->message, 'hunter2')
                && $job->payload->context['password'] === '******';
        });
    }

    public function test_it_sends_synchronously_when_the_queue_backend_is_unavailable(): void
    {
        config()->set('queue.connections.unavailable', ['driver' => 'redis', 'connection' => 'missing', 'queue' => 'logs']);
        config()->set('logcenter.queue.enabled', true);
        config()->set('logcenter.queue.connection', 'unavailable');

        $result = $this->app->make(LogDispatcher::class)->dispatch($this->makePayload());

        $this->assertTrue($result->isSuccess());
        $this->assertSame('null', $result->driver);
        $this->assertArrayNotHasKey('queued', $result->meta);
        $this->assertTrue($result->meta['discarded'] ?? false);
    }

    public function test_a_failing_sync_queue_job_is_not_sent_a_second_time(): void
    {
        config()->set('queue.connections.inline', ['driver' => 'sync']);
        config()->set('logcenter.queue.enabled', true);
        config()->set('logcenter.queue.connection', 'inline');
        config()->set('logcenter.default', 'telegram');
        config()->set('logcenter.drivers.telegram.token', 'TEST_TOKEN');
        config()->set('logcenter.drivers.telegram.chat_id', '12345');

        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => false], 500)]);

        try {
            $this->app->make(LogDispatcher::class)->dispatch($this->makePayload());
            $this->fail('The failed sync job should surface its exception.');
        } catch (RuntimeException) {
            // Expected: the inline job failed and the queue rethrew.
        }

        Http::assertSentCount(1);
    }
}
