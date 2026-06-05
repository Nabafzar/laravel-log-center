<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Tests\Unit\Suppression;

use Nabafzar\LogCenter\Suppression\SuppressionManager;
use Nabafzar\LogCenter\Tests\TestCase;

final class SuppressionManagerTest extends TestCase
{
    private SuppressionManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = new SuppressionManager;
        config()->set('logcenter.suppression.ttl', 300);
        config()->set('logcenter.suppression.store', 'array');
    }

    public function test_fingerprint_is_not_suppressed_before_first_send(): void
    {
        $this->assertFalse($this->manager->isSuppressed('abc123'));
    }

    public function test_fingerprint_is_suppressed_after_successful_send(): void
    {
        $this->manager->recordSuccessfulSend('abc123');

        $this->assertTrue($this->manager->isSuppressed('abc123'));
    }

    public function test_different_fingerprints_are_independent(): void
    {
        $this->manager->recordSuccessfulSend('fingerprint-a');

        $this->assertTrue($this->manager->isSuppressed('fingerprint-a'));
        $this->assertFalse($this->manager->isSuppressed('fingerprint-b'));
    }

    public function test_occurrence_count_starts_at_zero_and_increments(): void
    {
        $this->manager->recordSuccessfulSend('abc123');

        $this->assertSame(1, $this->manager->incrementAndGetCount('abc123'));
        $this->assertSame(2, $this->manager->incrementAndGetCount('abc123'));
        $this->assertSame(3, $this->manager->incrementAndGetCount('abc123'));
    }

    public function test_empty_fingerprint_is_never_suppressed(): void
    {
        $this->manager->recordSuccessfulSend('');

        $this->assertFalse($this->manager->isSuppressed(''));
    }

    public function test_is_disabled_by_default(): void
    {
        $this->assertFalse($this->manager->isEnabled());
    }

    public function test_is_enabled_when_configured(): void
    {
        config()->set('logcenter.suppression.enabled', true);

        $this->assertTrue($this->manager->isEnabled());
    }
}
