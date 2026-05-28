<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final class LogTopic
{
    public readonly string $tag;

    /**
     * @var list<string>
     */
    public readonly array $tags;

    public readonly string|int|null $topicId;

    /**
     * @param string|array<int, string> $tag
     * @param string|int|null $topicId
     * @param array<int, string> $tags
     */
    public function __construct(
        string|array $tag = '',
        string|int|null $topicId = null,
        array $tags = [],
    ) {
        $normalizedTags = $this->normalizeTags($tag, $tags);

        $this->tag = $normalizedTags[0] ?? '';
        $this->tags = $normalizedTags;
        $this->topicId = $topicId;
    }

    /**
     * @param string|array<int, string> $tag
     * @param array<int, string> $tags
     *
     * @return list<string>
     */
    private function normalizeTags(string|array $tag, array $tags): array
    {
        $values = is_array($tag) ? $tag : [$tag];
        $values = array_merge($values, $tags);
        $normalized = [];

        foreach ($values as $value) {
            if (! is_string($value)) {
                continue;
            }

            $value = trim($value);

            if ($value === '') {
                continue;
            }

            $normalized[$value] = $value;
        }

        return array_values($normalized);
    }
}
