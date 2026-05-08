<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Tests\Unit\Logging;

use Monolog\Level;
use Monolog\LogRecord;
use Nabafzar\LogCenter\Contracts\LogFormatterInterface;
use Nabafzar\LogCenter\Contracts\LogTransportInterface;
use Nabafzar\LogCenter\DTO\FormattedMessage;
use Nabafzar\LogCenter\DTO\LogPayload;
use Nabafzar\LogCenter\DTO\TransportResult;
use Nabafzar\LogCenter\Logging\LogCenterHandler;
use Nabafzar\LogCenter\Managers\TransportManager;
use Nabafzar\LogCenter\Processors\PayloadProcessor;
use Nabafzar\LogCenter\Services\LogDispatcher;
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

        $dispatcher = new LogDispatcher($manager, new PayloadProcessor());
        $handler = new LogCenterHandler($dispatcher);

        $result = $handler->handle(new LogRecord(
            datetime: now()->toDateTimeImmutable(),
            channel: 'testing',
            level: Level::Error,
            message: 'Something failed',
            context: [],
            extra: [],
        ));
    }
}
