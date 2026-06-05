<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Tests\Feature\Console;

use Nabafzar\LogCenter\Contracts\LogFormatterInterface;
use Nabafzar\LogCenter\Contracts\LogTransportInterface;
use Nabafzar\LogCenter\DTO\FormattedMessage;
use Nabafzar\LogCenter\DTO\LogPayload;
use Nabafzar\LogCenter\DTO\TransportResult;
use Nabafzar\LogCenter\Managers\TransportManager;
use Nabafzar\LogCenter\Tests\TestCase;

final class TestLogCommandTest extends TestCase
{
    public function test_it_sends_a_test_log_through_the_resolved_transport(): void
    {
        $captures = (object) [
            'processedPayload' => null,
            'formattedPayload' => null,
            'formattedMessage' => null,
        ];

        $formatter = new class($captures) implements LogFormatterInterface
        {
            public function __construct(
                private object $captures,
            ) {}

            public function format(LogPayload $payload): FormattedMessage
            {
                $this->captures->formattedPayload = $payload;

                return new FormattedMessage('formatted test log');
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
                $this->captures->formattedMessage = $message;

                return TransportResult::success('telegram', meta: [
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

        $this->app->instance(TransportManager::class, $manager);

        $this->artisan('logcenter:test-log', [
            'message' => 'Hello from the test command',
            '--level' => 'warning',
            '--channel' => 'console',
            '--driver' => 'telegram',
            '--formatter' => 'plain',
        ])->assertExitCode(0);

        $this->assertInstanceOf(LogPayload::class, $captures->processedPayload);
        $this->assertInstanceOf(LogPayload::class, $captures->formattedPayload);
        $this->assertInstanceOf(FormattedMessage::class, $captures->formattedMessage);
        $this->assertSame('WARNING', $captures->processedPayload->level);
        $this->assertSame('Hello from the test command', $captures->processedPayload->message);
        $this->assertSame('console', $captures->processedPayload->channel);
        $this->assertSame('console', $captures->processedPayload->source);
        $this->assertSame('formatted test log', $captures->formattedMessage->content);
    }

    public function test_it_rejects_invalid_levels_before_dispatching(): void
    {
        $manager = $this->createMock(TransportManager::class);
        $manager->expects($this->never())->method('formatter');
        $manager->expects($this->never())->method('driver');

        $this->app->instance(TransportManager::class, $manager);

        $this->artisan('logcenter:test-log', [
            '--level' => 'definitely-not-a-level',
        ])->assertExitCode(1);
    }
}
