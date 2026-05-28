<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Tests\Unit\Logging;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
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
        $formatter = $this->createMock(LogFormatterInterface::class);
        $formatter->method('format')->willReturn(new FormattedMessage('formatted'));

        $transport = $this->createMock(LogTransportInterface::class);
        $transport->expects($this->once())
            ->method('send')
            ->willThrowException(new RuntimeException('transport is unavailable'));
        $transport->method('supportedFormats')->willReturn([]);

        $manager = $this->createMock(TransportManager::class);
        $manager->method('defaultDriverName')->willReturn('telegram');
        $manager->method('formatter')->willReturn($formatter);
        $manager->method('driver')->willReturn($transport);

        $dispatcher = new LogDispatcher($manager, new PayloadProcessor(), new SuppressionManager());
        $handler = new LogCenterHandler($dispatcher, new AttributeResolver());

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
            'uses' => AnnotatedFixture::class . '@routeAction',
            'controller' => AnnotatedFixture::class . '@routeAction',
        ]));
        $this->app->instance('request', $request);

        $captures = new \stdClass();

        $formatter = $this->createMock(LogFormatterInterface::class);
        $formatter->method('format')->willReturn(new FormattedMessage('formatted'));

        $transport = new class ($captures) implements LogTransportInterface {
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

        $manager = $this->createMock(TransportManager::class);
        $manager->method('defaultDriverName')->willReturn('null');
        $manager->method('formatter')->willReturn($formatter);
        $manager->method('driver')->willReturn($transport);

        $dispatcher = new LogDispatcher($manager, new PayloadProcessor(), new SuppressionManager());
        $handler = new LogCenterHandler($dispatcher, new AttributeResolver());

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
}
