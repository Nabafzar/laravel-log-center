<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Tests\Unit\Viewer;

use Nabafzar\LogCenter\Tests\TestCase;
use Nabafzar\LogCenter\Viewer\CodeSnippet;

final class CodeSnippetTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->file = base_path('logcenter-snippet-fixture.php');
        file_put_contents($this->file, implode("\n", array_map(static fn (int $n): string => "// line {$n}", range(1, 30))));
    }

    protected function tearDown(): void
    {
        @unlink($this->file);

        parent::tearDown();
    }

    public function test_it_returns_the_lines_around_the_exception_line(): void
    {
        config()->set('logcenter.viewer.code_snippets', true);

        $snippet = (new CodeSnippet)->around($this->file, 10, 3);

        $this->assertSame(7, $snippet['start']);
        $this->assertSame(10, $snippet['line']);
        $this->assertSame(range(7, 13), array_keys($snippet['lines']));
        $this->assertSame('// line 10', $snippet['lines'][10]);
    }

    public function test_it_is_disabled_unless_configured(): void
    {
        config()->set('logcenter.viewer.code_snippets', false);

        $this->assertNull((new CodeSnippet)->around($this->file, 10));
    }

    public function test_it_never_reads_files_outside_the_application_or_non_php_files(): void
    {
        config()->set('logcenter.viewer.code_snippets', true);

        $outside = tempnam(sys_get_temp_dir(), 'logcenter').'.php';
        file_put_contents($outside, "<?php\n// secret\n");
        $text = base_path('logcenter-snippet-fixture.txt');
        file_put_contents($text, "line\nline\n");

        try {
            $snippets = new CodeSnippet;

            $this->assertNull($snippets->around($outside, 1));
            $this->assertNull($snippets->around($text, 1));
            $this->assertNull($snippets->around(base_path('../../../../etc/passwd'), 1));
            $this->assertNull($snippets->around($this->file, 999));
        } finally {
            @unlink($outside);
            @unlink($text);
        }
    }
}
