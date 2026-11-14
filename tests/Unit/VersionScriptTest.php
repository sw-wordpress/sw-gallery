<?php

declare(strict_types=1);

namespace SWGallery\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** bin/version.sh is the checked rule behind the single source of the version (plugin header); drift fails CI here. */
final class VersionScriptTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        $nodePath = trim((string) shell_exec('command -v node'));
        if ($nodePath === '') {
            self::markTestSkipped('Node is not available.');
        }
    }

    protected function tearDown(): void
    {
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            $this->sh('rm -rf ' . escapeshellarg($this->tmp));
        }
    }

    public function testRepositoryVersionsAreConsistent(): void
    {
        [$code, $output] = $this->sh(escapeshellarg(dirname(__DIR__, 2) . '/bin/version.sh') . ' check');
        self::assertSame(0, $code, $output);
    }

    public function testCheckDetectsDriftAndSetFixesIt(): void
    {
        $this->tmp = $this->fixture();
        $script = escapeshellarg(dirname(__DIR__, 2) . '/bin/version.sh');
        $env = 'SWG_VERSION_ROOT=' . escapeshellarg($this->tmp) . ' ';

        [$code] = $this->sh($env . $script . ' check');
        self::assertSame(0, $code);

        // Header bumped by hand, everything else stale.
        file_put_contents(
            $this->tmp . '/sw-gallery.php',
            str_replace('Version:           1.2.3', 'Version:           1.2.4', (string) file_get_contents($this->tmp . '/sw-gallery.php'))
        );
        [$code, $output] = $this->sh($env . $script . ' check');
        self::assertSame(1, $code);
        self::assertStringContainsString('SWGALLERY_VERSION', $output);
        self::assertStringContainsString('package.json', $output);
        self::assertStringContainsString('CHANGELOG.md', $output);

        [$code, $output] = $this->sh($env . $script . ' set 1.3.0-rc.1');
        self::assertSame(0, $code, $output);
        self::assertSame('1.3.0-rc.1', trim((string) shell_exec($env . $script . ' get')));
        $changelog = (string) file_get_contents($this->tmp . '/CHANGELOG.md');
        self::assertMatchesRegularExpression('/## \[Unreleased\]\n\n## \[1\.3\.0-rc\.1\] - \d{4}-\d{2}-\d{2}/', $changelog);
        self::assertStringContainsString('"version": "1.3.0-rc.1"', (string) file_get_contents($this->tmp . '/assets/package.json'));

        [$code] = $this->sh($env . $script . ' set not-a-version');
        self::assertNotSame(0, $code);
    }

    private function fixture(): string
    {
        $dir = sys_get_temp_dir() . '/swg-version-' . bin2hex(random_bytes(4));
        mkdir($dir . '/assets', 0777, true);
        file_put_contents(
            $dir . '/sw-gallery.php',
            "<?php\n/**\n * Plugin Name:       X\n * Version:           1.2.3\n */\ndefine( 'SWGALLERY_VERSION', '1.2.3' );\n"
        );
        file_put_contents($dir . '/assets/package.json', "{\n  \"name\": \"x\",\n  \"version\": \"1.2.3\"\n}\n");
        $lock = array('version' => '1.2.3', 'packages' => array('' => array('version' => '1.2.3')));
        file_put_contents($dir . '/assets/package-lock.json', json_encode($lock, JSON_PRETTY_PRINT) . "\n");
        file_put_contents($dir . '/CHANGELOG.md', "# Changelog\n\n## [Unreleased]\n\n## [1.2.3] - 2026-01-01\n");

        return $dir;
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function sh(string $command): array
    {
        $output = array();
        exec($command . ' 2>&1', $output, $code);

        return array($code, implode("\n", $output));
    }
}
