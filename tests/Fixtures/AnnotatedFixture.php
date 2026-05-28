<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Tests\Fixtures;

use Nabafzar\LogCenter\Attributes\AttributeResolver;
use Nabafzar\LogCenter\Attributes\LogTopic;

/**
 * Fixture class used to verify that AttributeResolver finds #[LogTopic]
 * on a method in the real call stack.
 */
final class AnnotatedFixture
{
    #[LogTopic('orders', topicId: 5678)]
    public static function callAndResolve(AttributeResolver $resolver): ?LogTopic
    {
        return $resolver->resolve();
    }

    #[LogTopic('mattermost', topicId: 'thread-abc')]
    public static function callAndResolveStringTopicId(AttributeResolver $resolver): ?LogTopic
    {
        return $resolver->resolve();
    }

    #[LogTopic('no-topic-id')]
    public static function callAndResolveWithoutTopicId(AttributeResolver $resolver): ?LogTopic
    {
        return $resolver->resolve();
    }

    public static function callWithoutAttribute(AttributeResolver $resolver): ?LogTopic
    {
        return $resolver->resolve();
    }

    #[LogTopic(tags: ['payments', 'bale'])]
    public function routeAction(): void
    {
    }
}

/** Fixture for verifying class-level #[LogTopic] resolution. */
#[LogTopic('billing', topicId: 2000)]
final class ClassAnnotatedFixture
{
    public static function callAndResolve(AttributeResolver $resolver): ?LogTopic
    {
        return $resolver->resolve();
    }
}
