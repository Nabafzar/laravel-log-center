<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Tests\Unit\Jobs;

use Nabafzar\LogCenter\Contracts\LogFormatterInterface;
use Nabafzar\LogCenter\Contracts\LogTransportInterface;
use Nabafzar\LogCenter\DTO\FormattedMessage;
use Nabafzar\LogCenter\DTO\LogPayload;
use Nabafzar\LogCenter\DTO\TransportResult;
use Nabafzar\LogCenter\Jobs\DispatchLogJob;
use Nabafzar\LogCenter\Managers\TransportManager;
use Nabafzar\LogCenter\Processors\PayloadProcessor;
use Nabafzar\LogCenter\Services\LogDispatcher;
use Nabafzar\LogCenter\Tests\TestCase;

final class DispatchLogJobTest extends TestCase
{
    public function test_it_forwards_the_payload_to_the_dispatcher(): void
    {
        $payload = $this->makePayload();
        $job = new DispatchLogJob($payload, 'telegram', 'markdown');

        $captures = (object) [
            'message' => null,
        ];

        $formatter = new class () implements LogFormatterInterface {
            public function format(LogPayload $payload): FormattedMessage
            {
                return new FormattedMessage('job formatted');
            }
        };

        $transport = new class ($captures) implements LogTransportInterface {
            public function __construct(
                private object $captures,
            ) {}

            public function send(LogPayload $payload, FormattedMessage $message): TransportResult
            {
                $this->captures->message = $message->content;

                return TransportResult::success('telegram', meta: [
                    'message' => $message->content,
                ]);
            }

            public function supportedFormats(): array
            {
                return [];
            }
        };

        $manager = $this->createMock(TransportManager::class);
        $manager->expects($this->once())
            ->method('formatter')
            ->with('markdown', 'telegram')
            ->willReturn($formatter);

        $manager->expects($this->once())
            ->method('driver')
            ->with('telegram')
            ->willReturn($transport);

        $manager->method('defaultDriverName')->willReturn('telegram');

        $dispatcher = new LogDispatcher($manager, new PayloadProcessor());

        $job->handle($dispatcher);

        $this->assertSame('telegram', $job->driver);
        $this->assertSame('markdown', $job->formatter);
        $this->assertSame('job formatted', $captures->message);
    }
}
