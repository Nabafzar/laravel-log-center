<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Tests\Unit\Transports;

use Nabafzar\LogCenter\DTO\FormattedMessage;
use Nabafzar\LogCenter\Enums\MessageFormatEnum;
use Nabafzar\LogCenter\Tests\TestCase;
use Nabafzar\LogCenter\Transports\Null\NullTransport;

final class NullTransportTest extends TestCase
{
    public function test_it_discards_messages_successfully(): void
    {
        $transport = new NullTransport;
        $result = $transport->send($this->makePayload(), new FormattedMessage('ignored'));

        $this->assertTrue($result->isSuccess());
        $this->assertSame('null', $result->driver);
        $this->assertTrue($result->meta['discarded']);
        $this->assertContains(MessageFormatEnum::PLAIN, $transport->supportedFormats());
    }

    public function test_it_does_not_support_topics(): void
    {
        $this->assertFalse((new NullTransport)->supportsTopics());
    }
}
