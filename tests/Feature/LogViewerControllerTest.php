<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Tests\Feature;

use Nabafzar\LogCenter\Tests\TestCase;

final class LogViewerControllerTest extends TestCase
{
    public function test_it_renders_the_selected_log_file(): void
    {
        $directory = storage_path('logs/logcenter-feature-tests');
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $file = $directory . '/feature.log';
        file_put_contents($file, <<<LOG
[2026-05-08 10:00:00] local.ERROR: Feature test failure
LOG);

        config()->set('logcenter.viewer.paths', [$directory]);

        $response = $this->get('/log-viewer?file=' . urlencode($file));

        $response->assertOk();
        $response->assertSee('Feature test failure');
        $response->assertSee('Log Center Test App');
    }
}
