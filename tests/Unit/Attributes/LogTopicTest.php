<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Tests\Unit\Attributes;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Http;
use Nabafzar\LogCenter\Attributes\AttributeResolver;
use Nabafzar\LogCenter\Attributes\LogTopic;
use Nabafzar\LogCenter\Contracts\LogFormatterInterface;
use Nabafzar\LogCenter\Contracts\LogTransportInterface;
use Nabafzar\LogCenter\DTO\FormattedMessage;
use Nabafzar\LogCenter\DTO\LogPayload;
use Nabafzar\LogCenter\DTO\TransportResult;
use Nabafzar\LogCenter\Managers\TransportManager;
use Nabafzar\LogCenter\Processors\PayloadProcessor;
use Nabafzar\LogCenter\Services\LogDispatcher;
use Nabafzar\LogCenter\Suppression\SuppressionManager;
use Nabafzar\LogCenter\Tests\Fixtures\AnnotatedFixture;
use Nabafzar\LogCenter\Tests\Fixtures\ClassAnnotatedFixture;
use Nabafzar\LogCenter\Tests\TestCase;
use Nabafzar\LogCenter\Transports\Telegram\TelegramTransport;
use ReflectionClass;

final class LogTopicTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Attribute data

    public function test_attribute_stores_tag_without_topic_id(): void
    {
        $attr = new LogTopic('payments');

        $this->assertSame('payments', $attr->tag);
        $this->assertSame(['payments'], $attr->tags);
        $this->assertNull($attr->topicId);
    }

    public function test_attribute_stores_tag_and_topic_id(): void
    {
        $attr = new LogTopic('emergency', topicId: 1234);

        $this->assertSame('emergency', $attr->tag);
        $this->assertSame(['emergency'], $attr->tags);
        $this->assertSame(1234, $attr->topicId);
    }

    public function test_attribute_allows_string_topic_id(): void
    {
        $attr = new LogTopic('mattermost', topicId: 'thread-abc');

        $this->assertSame('mattermost', $attr->tag);
        $this->assertSame(['mattermost'], $attr->tags);
        $this->assertSame('thread-abc', $attr->topicId);
    }

    public function test_attribute_stores_multiple_tags(): void
    {
        $attr = new LogTopic(tags: ['payments', 'checkout', 'payments']);

        $this->assertSame('payments', $attr->tag);
        $this->assertSame(['payments', 'checkout'], $attr->tags);
        $this->assertNull($attr->topicId);
    }

    public function test_attribute_accepts_multiple_tags_as_the_first_argument(): void
    {
        $attr = new LogTopic(['payments', 'checkout'], topicId: 1234);

        $this->assertSame('payments', $attr->tag);
        $this->assertSame(['payments', 'checkout'], $attr->tags);
        $this->assertSame(1234, $attr->topicId);
    }

    public function test_attribute_can_be_read_via_reflection(): void
    {
        $ref = new ReflectionClass(AnnotatedFixture::class);
        $attrs = $ref->getMethod('callAndResolve')->getAttributes(LogTopic::class);

        $this->assertCount(1, $attrs);

        $attr = $attrs[0]->newInstance();
        $this->assertSame('orders', $attr->tag);
        $this->assertSame(['orders'], $attr->tags);
        $this->assertSame(5678, $attr->topicId);
    }

    // -------------------------------------------------------------------------
    // Resolver — call-stack detection

    public function test_resolver_finds_attribute_on_a_decorated_method(): void
    {
        $resolver = new AttributeResolver;
        $topic = AnnotatedFixture::callAndResolve($resolver);

        $this->assertNotNull($topic);
        $this->assertSame('orders', $topic->tag);
        $this->assertSame(['orders'], $topic->tags);
        $this->assertSame(5678, $topic->topicId);
    }

    public function test_resolver_finds_attribute_when_topic_id_is_absent(): void
    {
        $resolver = new AttributeResolver;
        $topic = AnnotatedFixture::callAndResolveWithoutTopicId($resolver);

        $this->assertNotNull($topic);
        $this->assertSame('no-topic-id', $topic->tag);
        $this->assertSame(['no-topic-id'], $topic->tags);
        $this->assertNull($topic->topicId);
    }

    public function test_resolver_preserves_string_topic_id(): void
    {
        $resolver = new AttributeResolver;
        $topic = AnnotatedFixture::callAndResolveStringTopicId($resolver);

        $this->assertNotNull($topic);
        $this->assertSame('mattermost', $topic->tag);
        $this->assertSame(['mattermost'], $topic->tags);
        $this->assertSame('thread-abc', $topic->topicId);
    }

    public function test_resolver_returns_null_when_no_attribute_is_in_the_stack(): void
    {
        $resolver = new AttributeResolver;
        $topic = AnnotatedFixture::callWithoutAttribute($resolver);

        $this->assertNull($topic);
    }

    public function test_resolver_caches_results_for_the_same_method(): void
    {
        $resolver = new AttributeResolver;

        $first = AnnotatedFixture::callAndResolve($resolver);
        $second = AnnotatedFixture::callAndResolve($resolver);

        $this->assertEquals($first, $second);
    }

    public function test_resolver_finds_class_level_attribute_when_method_has_none(): void
    {
        $resolver = new AttributeResolver;
        $topic = ClassAnnotatedFixture::callAndResolve($resolver);

        $this->assertNotNull($topic);
        $this->assertSame('billing', $topic->tag);
        $this->assertSame(2000, $topic->topicId);
    }

    // -------------------------------------------------------------------------
    // Dispatcher — hashtag injection

    public function test_dispatcher_sends_successfully_with_tags(): void
    {
        $payload = $this->makePayload(['tags' => ['payments']]);

        $result = $this->makeDispatcher()->sendNow($payload, 'null');

        $this->assertTrue($result->isSuccess());
    }

    public function test_dispatcher_sends_successfully_without_tags(): void
    {
        $payload = $this->makePayload(['tags' => []]);

        $result = $this->makeDispatcher()->sendNow($payload, 'null');

        $this->assertTrue($result->isSuccess());
    }

    // -------------------------------------------------------------------------
    // Telegram — attribute topicId overrides config

    public function test_telegram_uses_attribute_topic_id_over_config_mapping(): void
    {
        config()->set('logcenter.drivers.telegram.token', 'TEST_TOKEN');
        config()->set('logcenter.drivers.telegram.chat_id', '12345');
        config()->set('logcenter.drivers.telegram.topic_id', 999); // default config topic

        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);

        $payload = $this->makePayload([
            'tags' => ['payments'],
            'meta' => ['logcenter_topic_id' => 1234],
        ]);

        (new TelegramTransport)->send($payload, new FormattedMessage('hello'));

        Http::assertSent(static function ($request): bool {
            return ($request->data()['message_thread_id'] ?? null) === 1234;
        });
    }

    public function test_telegram_routes_to_topic_by_tag_name(): void
    {
        config()->set('logcenter.drivers.telegram.token', 'TEST_TOKEN');
        config()->set('logcenter.drivers.telegram.chat_id', '12345');
        config()->set('logcenter.drivers.telegram.tag_topics', ['payments' => 9999]);

        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);

        $payload = $this->makePayload(['tags' => ['payments'], 'meta' => []]);

        (new TelegramTransport)->send($payload, new FormattedMessage('hello'));

        Http::assertSent(static function ($request): bool {
            return ($request->data()['message_thread_id'] ?? null) === 9999;
        });
    }

    public function test_telegram_tag_topic_takes_priority_over_level_topic(): void
    {
        config()->set('logcenter.drivers.telegram.token', 'TEST_TOKEN');
        config()->set('logcenter.drivers.telegram.chat_id', '12345');
        config()->set('logcenter.drivers.telegram.tag_topics', ['payments' => 9999]);
        config()->set('logcenter.drivers.telegram.topics_level', ['error' => 111]);

        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);

        $payload = $this->makePayload(['tags' => ['payments'], 'meta' => []]);

        (new TelegramTransport)->send($payload, new FormattedMessage('hello'));

        Http::assertSent(static function ($request): bool {
            return ($request->data()['message_thread_id'] ?? null) === 9999;
        });
    }

    public function test_telegram_falls_back_to_config_when_no_attribute_topic_id(): void
    {
        config()->set('logcenter.drivers.telegram.token', 'TEST_TOKEN');
        config()->set('logcenter.drivers.telegram.chat_id', '12345');
        config()->set('logcenter.drivers.telegram.topic_id', 999);

        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);

        $payload = $this->makePayload(['tags' => [], 'meta' => []]);

        (new TelegramTransport)->send($payload, new FormattedMessage('hello'));

        Http::assertSent(static function ($request): bool {
            return ($request->data()['message_thread_id'] ?? null) === 999;
        });
    }

    public function test_resolve_cache_returns_consistent_result_on_repeated_calls(): void
    {
        $resolver = new AttributeResolver;

        // Call resolve() three times from the same stack context.
        $first = $resolver->resolve();
        $second = $resolver->resolve();
        $third = $resolver->resolve();

        // All calls must return the same value — the cache must not flip results.
        $this->assertEquals($first, $second);
        $this->assertEquals($second, $third);
    }

    public function test_resolve_cache_is_consistent_for_annotated_and_unannotated_callers(): void
    {
        $resolver = new AttributeResolver;

        // Annotated stack should always return a topic.
        $annotated = AnnotatedFixture::callAndResolve($resolver);
        $this->assertNotNull($annotated);
        $this->assertInstanceOf(LogTopic::class, $annotated);

        // Unannotated stack should always return null.
        $unannotated = AnnotatedFixture::callWithoutAttribute($resolver);
        $this->assertNull($unannotated);

        // Calling annotated again must still return the topic (cache stable).
        $annotatedAgain = AnnotatedFixture::callAndResolve($resolver);
        $this->assertEquals($annotated, $annotatedAgain);
    }

    // -------------------------------------------------------------------------

    private function makeDispatcher(): LogDispatcher
    {
        $manager = $this->createStub(TransportManager::class);
        $manager->method('defaultDriverName')->willReturn('null');
        $manager->method('formatter')->willReturn(
            new class implements LogFormatterInterface
            {
                public function format(LogPayload $p): FormattedMessage
                {
                    return new FormattedMessage('formatted');
                }
            },
        );
        $manager->method('driver')->willReturn(
            new class implements LogTransportInterface
            {
                public function send(LogPayload $p, FormattedMessage $m): TransportResult
                {
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
            },
        );

        return new LogDispatcher(
            $manager,
            new PayloadProcessor,
            new SuppressionManager,
        );
    }

    public function test_route_fallback_is_not_reused_for_a_different_route(): void
    {
        $resolver = new AttributeResolver;

        $annotated = Request::create('/payments', 'GET');
        $annotated->setRouteResolver(static fn (): Route => new Route('GET', '/payments', [
            'uses' => AnnotatedFixture::class.'@routeAction',
        ]));
        $this->app->instance('request', $annotated);

        $this->assertSame(['payments', 'bale'], $resolver->resolve()?->tags);

        $plain = Request::create('/other', 'GET');
        $plain->setRouteResolver(static fn (): Route => new Route('GET', '/other', [
            'uses' => static fn (): string => 'ok',
        ]));
        $this->app->instance('request', $plain);

        $this->assertNull($resolver->resolve());
    }
}
