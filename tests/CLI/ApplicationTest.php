<?php

declare(strict_types=1);

namespace Tests\CLI;

use Dalehurley\Phpbot\CLI\Application;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Application::class)]
class ApplicationTest extends TestCase
{
    public function testConstruct(): void
    {
        $app = new Application([]);
        $this->assertInstanceOf(Application::class, $app);
    }

    public function testRunHelpReturnsZero(): void
    {
        ob_start();
        $app = new Application(['api_key' => 'x']);
        $exitCode = $app->run(['phpbot', '--help']);
        $output = ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('PhpBot', $output);
        $this->assertStringContainsString('Usage', $output);
    }

    public function testRunVersionReturnsZero(): void
    {
        ob_start();
        $app = new Application(['api_key' => 'x']);
        $exitCode = $app->run(['phpbot', '--version']);
        $output = ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('PhpBot', $output);
        $this->assertStringContainsString('v1.0.0', $output);
    }
}
