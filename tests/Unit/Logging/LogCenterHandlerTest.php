<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Tests\Unit\Logging;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Monolog\Level;
use Monolog\LogRecord;
use Nabafzar\LogCenter\Attributes\AttributeResolver;
use Nabafzar\LogCenter\Contracts\LogFormatterInterface;
use Nabafzar\LogCenter\Contracts\LogTransportInterface;
use Nabafzar\LogCenter\DTO\FormattedMessage;
use Nabafzar\LogCenter\DTO\LogPayload;
use Nabafzar\LogCenter\DTO\TransportResult;
use Nabafzar\LogCenter\Logging\LogCenterHandler;
use Nabafzar\LogCenter\Managers\TransportManager;
use Nabafzar\LogCenter\Processors\PayloadProcessor;
use Nabafzar\LogCenter\Services\LogDispatcher;
use Nabafzar\LogCenter\Suppression\SuppressionManager;
use Nabafzar\LogCenter\Tests\Fixtures\AnnotatedFixture;
use Nabafzar\LogCenter\Tests\TestCase;
use RuntimeException;

final class LogCenterHandlerTest extends TestCase
{
    public function test_it_swallows_dispatch_failures_without_throwing(): void
    {
        $formatter = $this->createStub(LogFormatterInterface::class);
        $formatter->method('format')->willReturn(new FormattedMessage('formatted'));

        $transport = $this->createMock(LogTransportInterface::class);
        $transport->expects($this->once())
            ->method('send')
            ->willThrowException(new RuntimeException('transport is unavailable'));
        $transport->method('supportedFormats')->willReturn([]);

        $manager = $this->createStub(TransportManager::class);
        $manager->method('defaultDriverName')->willReturn('telegram');
        $manager->method('formatter')->willReturn($formatter);
        $manager->method('driver')->willReturn($transport);

        $dispatcher = new LogDispatcher($manager, new PayloadProcessor, new SuppressionManager);
        $handler = new LogCenterHandler($dispatcher, new AttributeResolver);

        $result = $handler->handle(new LogRecord(
            datetime: now()->toDateTimeImmutable(),
            channel: 'testing',
            level: Level::Error,
            message: 'Something failed',
            context: [],
            extra: [],
        ));
    }

    public function test_it_resolves_log_topic_from_the_current_route_action(): void
    {
        $request = Request::create('/payments', 'GET');
        $request->setRouteResolver(static fn (): Route => new Route('GET', '/payments', [
            'uses' => AnnotatedFixture::class.'@routeAction',
            'controller' => AnnotatedFixture::class.'@routeAction',
        ]));
        $this->app->instance('request', $request);

        $captures = new \stdClass;

        $formatter = $this->createStub(LogFormatterInterface::class);
        $formatter->method('format')->willReturn(new FormattedMessage('formatted'));

        $transport = new class($captures) implements LogTransportInterface
        {
            public function __construct(private readonly \stdClass $captures) {}

            public function send(LogPayload $payload, FormattedMessage $message): TransportResult
            {
                $this->captures->payload = $payload;

                return TransportResult::success('null', 200);
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

        $manager = $this->createStub(TransportManager::class);
        $manager->method('defaultDriverName')->willReturn('null');
        $manager->method('formatter')->willReturn($formatter);
        $manager->method('driver')->willReturn($transport);

        $dispatcher = new LogDispatcher($manager, new PayloadProcessor, new SuppressionManager);
        $handler = new LogCenterHandler($dispatcher, new AttributeResolver);

        $handler->handle(new LogRecord(
            datetime: now()->toDateTimeImmutable(),
            channel: 'testing',
            level: Level::Error,
            message: 'Something failed',
            context: [],
            extra: [],
        ));

        $this->assertSame(['payments', 'bale'], $captures->payload->tags);
    }

    public function test_it_ignores_records_below_the_configured_logcenter_level(): void
    {
        config()->set('logcenter.level', 'error');

        $handler = $this->app->make(LogCenterHandler::class, ['level' => Level::Debug]);

        $this->assertFalse($handler->isHandling($this->makeRecord(level: 'warning')));
        $this->assertTrue($handler->isHandling($this->makeRecord(level: 'error')));
        $this->assertTrue($handler->isHandling($this->makeRecord(level: 'critical')));
    }

    public function test_the_channel_level_still_applies_when_it_is_stricter(): void
    {
        config()->set('logcenter.level', 'debug');

        $handler = $this->app->make(LogCenterHandler::class, ['level' => Level::Critical]);

        $this->assertFalse($handler->isHandling($this->makeRecord(level: 'error')));
        $this->assertTrue($handler->isHandling($this->makeRecord(level: 'critical')));
    }

    public function test_laravel_log_channel_delivers_through_the_configured_transport(): void
    {
        config()->set('logging.channels.logcenter', [
            'driver' => 'monolog',
            'handler' => LogCenterHandler::class,
        ]);
        config()->set('logcenter.level', 'error');
        config()->set('logcenter.default', 'telegram');
        config()->set('logcenter.drivers.telegram.token', 'TEST_TOKEN');
        config()->set('logcenter.drivers.telegram.chat_id', '12345');

        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ]);

        Log::channel('logcenter')->debug('Debug noise');
        Log::channel('logcenter')->warning('Warning noise');
        Log::channel('logcenter')->error('Payment failed', ['password' => 'hunter2']);

        Http::assertSentCount(1);
        Http::assertSent(static fn ($request): bool => str_contains($request->data()['text'], 'Payment failed')
            && ! str_contains($request->data()['text'], 'hunter2'));
    }

    public function test_logging_from_inside_a_dispatch_does_not_recurse(): void
    {
        $calls = (object) ['count' => 0];
        $transport = new class($calls) implements LogTransportInterface
        {
            public ?LogCenterHandler $handler = null;

            public function __construct(private readonly \stdClass $calls) {}

            public function send(LogPayload $payload, FormattedMessage $message): TransportResult
            {
                $this->calls->count++;

                // Simulates a listener or HTTP middleware that logs while sending.
                $this->handler?->handle(new LogRecord(
                    datetime: now()->toDateTimeImmutable(),
                    channel: 'testing',
                    level: Level::Error,
                    message: 'Nested log',
                ));

                return TransportResult::failed('null', 'down');
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

        $formatter = $this->createStub(LogFormatterInterface::class);
        $formatter->method('format')->willReturn(new FormattedMessage('formatted'));

        $manager = $this->createStub(TransportManager::class);
        $manager->method('defaultDriverName')->willReturn('null');
        $manager->method('formatter')->willReturn($formatter);
        $manager->method('driver')->willReturn($transport);

        $handler = new LogCenterHandler(
            new LogDispatcher($manager, new PayloadProcessor, new SuppressionManager),
            new AttributeResolver,
        );
        $transport->handler = $handler;

        $handler->handle($this->makeRecord());

        $this->assertSame(1, $calls->count);
    }
}
