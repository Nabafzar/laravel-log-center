<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Tests\Unit\Factories;

use Illuminate\Http\Request;
use Monolog\Level;
use Monolog\LogRecord;
use Nabafzar\LogCenter\Attributes\LogTopic;
use Nabafzar\LogCenter\Factories\LogPayloadFactory;
use Nabafzar\LogCenter\Tests\TestCase;
use RuntimeException;

final class LogPayloadFactoryTest extends TestCase
{
    public function test_it_converts_a_log_record_into_a_payload(): void
    {
        $request = Request::create('/orders?ref=42', 'POST', [
            'password' => 'secret',
            'status' => 'pending',
        ]);
        $request->headers->set('Referer', 'https://example.test/account?token=abc123');
        $request->headers->set('Cookie', 'laravel_session=session-cookie');
        $request->headers->set('Authorization', 'Bearer bearer-token');
        $request->setUserResolver(static function (): object {
            return new class
            {
                public function getAuthIdentifier(): int
                {
                    return 42;
                }

                public string $name = 'Alice';
            };
        });

        $record = new LogRecord(
            datetime: now()->toDateTimeImmutable(),
            channel: 'testing',
            level: Level::Error,
            message: 'Checkout failed',
            context: [
                'request' => $request,
                'user' => [
                    'id' => 99,
                ],
                'exception' => new RuntimeException('Boom'),
                'trace' => 'stack-trace',
                'secret' => 'hidden',
            ],
            extra: [
                'token' => 'extra-token',
            ],
        );

        $payload = LogPayloadFactory::fromLogRecord($record);

        $this->assertSame('ERROR', $payload->level);
        $this->assertSame('Checkout failed', $payload->message);
        $this->assertSame('testing', $payload->channel);
        $this->assertSame('Log Center Test App', $payload->application);
        $this->assertNotEmpty($payload->environment);
        $this->assertSame(42, $payload->request['user_id']);
        $this->assertSame('Alice', $payload->request['user_name']);
        $this->assertSame(99, $payload->user['id']);
        $this->assertSame('Alice', $payload->user['name']);
        $this->assertSame('RuntimeException', $payload->exception['class']);
        $this->assertSame('Boom', $payload->exception['message']);
        $this->assertSame('https://example.test/account', $payload->request['referer']);
        $this->assertNotEmpty($payload->trace);
        $this->assertStringContainsString('LogPayloadFactoryTest', $payload->trace ?? '');
        $this->assertNotEmpty($payload->fingerprint);
        $this->assertArrayNotHasKey('request', $payload->context);
        $this->assertArrayNotHasKey('userId', $payload->context);
        $this->assertArrayNotHasKey('headers', $payload->request);
        $this->assertContains($payload->source, ['http', 'console']);
    }

    public function test_it_normalizes_referer_without_decoding_reserved_characters(): void
    {
        $request = Request::create('/orders', 'GET');
        $request->headers->set('Referer', 'https://example.test/a%2Fb?token=abc%3F123#frag%0Ament');

        $record = new LogRecord(
            datetime: now()->toDateTimeImmutable(),
            channel: 'testing',
            level: Level::Error,
            message: 'Referer test',
            context: [
                'request' => $request,
            ],
            extra: [],
        );

        $payload = LogPayloadFactory::fromLogRecord($record);

        $this->assertSame('https://example.test/a%2Fb', $payload->request['referer']);
        $this->assertStringNotContainsString("\n", $payload->request['referer']);
        $this->assertStringNotContainsString('?', $payload->request['referer']);
    }

    public function test_it_sanitizes_request_referer_without_leaking_query_fragment_or_control_characters(): void
    {
        $request = Request::create('/orders', 'GET');
        $request->headers->set('Referer', 'https://example.test/path%2Fsegment?next=%2Fadmin#frag%0Ament');

        $record = new LogRecord(
            datetime: now()->toDateTimeImmutable(),
            channel: 'testing',
            level: Level::Error,
            message: 'Sanitized referer test',
            context: [
                'request' => $request,
            ],
            extra: [],
        );

        $payload = LogPayloadFactory::fromLogRecord($record);

        $this->assertSame('https://example.test/path%2Fsegment', $payload->request['referer']);
        $this->assertStringNotContainsString('?', $payload->request['referer']);
        $this->assertStringNotContainsString('#', $payload->request['referer']);
        $this->assertStringNotContainsString("\n", $payload->request['referer']);
    }

    public function test_it_adds_all_log_topic_tags_to_the_payload(): void
    {
        $record = new LogRecord(
            datetime: now()->toDateTimeImmutable(),
            channel: 'testing',
            level: Level::Error,
            message: 'Tagged log',
            context: [],
            extra: [],
        );

        $payload = LogPayloadFactory::fromLogRecord($record, new LogTopic(tags: ['payments', 'checkout']));

        $this->assertSame(['payments', 'checkout'], $payload->tags);
    }

    public function test_it_uses_empty_tags_and_meta_when_no_topic_is_passed(): void
    {
        $record = new LogRecord(
            datetime: now()->toDateTimeImmutable(),
            channel: 'testing',
            level: Level::Error,
            message: 'No topic',
            context: [],
            extra: [],
        );

        $payload = LogPayloadFactory::fromLogRecord($record);

        $this->assertSame([], $payload->tags);
        $this->assertSame([], $payload->meta);
    }

    public function test_it_uses_empty_meta_when_topic_has_no_topic_id(): void
    {
        $record = new LogRecord(
            datetime: now()->toDateTimeImmutable(),
            channel: 'testing',
            level: Level::Error,
            message: 'Tag only',
            context: [],
            extra: [],
        );

        $payload = LogPayloadFactory::fromLogRecord($record, new LogTopic('payments'));

        $this->assertSame(['payments'], $payload->tags);
        $this->assertSame([], $payload->meta);
    }

    public function test_it_preserves_string_topic_id_in_payload_meta(): void
    {
        $record = new LogRecord(
            datetime: now()->toDateTimeImmutable(),
            channel: 'testing',
            level: Level::Error,
            message: 'Tagged log',
            context: [],
            extra: [],
        );

        $payload = LogPayloadFactory::fromLogRecord($record, new LogTopic('mattermost', topicId: 'thread-abc'));

        $this->assertSame('thread-abc', $payload->meta['logcenter_topic_id']);
    }

    public function test_it_reads_topic_id_from_context_when_no_attribute_is_present(): void
    {
        $record = new LogRecord(
            datetime: now()->toDateTimeImmutable(),
            channel: 'testing',
            level: Level::Error,
            message: 'Job failed',
            context: ['logcenter_topic_id' => 1234],
            extra: [],
        );

        $payload = LogPayloadFactory::fromLogRecord($record);

        $this->assertSame(1234, $payload->meta['logcenter_topic_id']);
        $this->assertArrayNotHasKey('logcenter_topic_id', $payload->context);
    }

    public function test_it_reads_tags_array_from_context_when_no_attribute_is_present(): void
    {
        $record = new LogRecord(
            datetime: now()->toDateTimeImmutable(),
            channel: 'testing',
            level: Level::Error,
            message: 'Job failed',
            context: ['logcenter_tags' => ['payments', 'checkout']],
            extra: [],
        );

        $payload = LogPayloadFactory::fromLogRecord($record);

        $this->assertSame(['payments', 'checkout'], $payload->tags);
        $this->assertArrayNotHasKey('logcenter_tags', $payload->context);
    }

    public function test_it_reads_single_string_tag_from_context(): void
    {
        $record = new LogRecord(
            datetime: now()->toDateTimeImmutable(),
            channel: 'testing',
            level: Level::Error,
            message: 'Job failed',
            context: ['logcenter_tags' => 'payments'],
            extra: [],
        );

        $payload = LogPayloadFactory::fromLogRecord($record);

        $this->assertSame(['payments'], $payload->tags);
    }

    public function test_attribute_takes_priority_over_context_topic_id(): void
    {
        $record = new LogRecord(
            datetime: now()->toDateTimeImmutable(),
            channel: 'testing',
            level: Level::Error,
            message: 'Job failed',
            context: ['logcenter_topic_id' => 9999],
            extra: [],
        );

        $payload = LogPayloadFactory::fromLogRecord($record, new LogTopic('payments', topicId: 1234));

        $this->assertSame(1234, $payload->meta['logcenter_topic_id']);
    }

    public function test_attribute_takes_priority_over_context_tags(): void
    {
        $record = new LogRecord(
            datetime: now()->toDateTimeImmutable(),
            channel: 'testing',
            level: Level::Error,
            message: 'Job failed',
            context: ['logcenter_tags' => ['context-tag']],
            extra: [],
        );

        $payload = LogPayloadFactory::fromLogRecord($record, new LogTopic('attr-tag'));

        $this->assertSame(['attr-tag'], $payload->tags);
    }

    public function test_it_reads_string_topic_id_from_context(): void
    {
        $record = new LogRecord(
            datetime: now()->toDateTimeImmutable(),
            channel: 'testing',
            level: Level::Error,
            message: 'Job failed',
            context: ['logcenter_topic_id' => 'thread-abc'],
            extra: [],
        );

        $payload = LogPayloadFactory::fromLogRecord($record);

        $this->assertSame('thread-abc', $payload->meta['logcenter_topic_id']);
    }

    public function test_it_builds_a_payload_for_messages_with_invalid_utf8(): void
    {
        $payload = LogPayloadFactory::fromLogRecord($this->makeRecord(message: "broken \xB1 bytes"));

        $this->assertNotEmpty($payload->fingerprint);
    }

    public function test_it_keeps_only_scalar_context_tags(): void
    {
        $payload = LogPayloadFactory::fromLogRecord($this->makeRecord(context: [
            'logcenter_tags' => ['payments', ['nested'], null, 42, ' payments ', ''],
        ]));

        $this->assertSame(['payments', '42'], $payload->tags);
    }
}
