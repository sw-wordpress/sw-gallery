<?php

declare(strict_types=1);

namespace SWGallery\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** bin/verify-zip.sh accepts a minimal valid package and rejects every class of unwanted content (tiny fake ZIPs, no build needed). */
final class VerifyZipScriptTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        foreach (array('zip', 'unzip', 'md5sum') as $tool) {
            if (trim((string) shell_exec('command -v ' . $tool)) === '') {
                self::markTestSkipped($tool . ' is not available.');
            }
        }
        $this->tmp = sys_get_temp_dir() . '/swg-verify-' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            exec('rm -rf ' . escapeshellarg($this->tmp));
        }
    }

    public function testMinimalValidPackageIsAccepted(): void
    {
        [$code, $output] = $this->verify($this->zip(array()));
        self::assertSame(0, $code, $output);
        self::assertStringContainsString('OK:', $output);
    }

    /**
     * @return array<string, array{0: array<string, string|null>, 1: string, 2?: string}>
     */
    public static function badPackages(): array
    {
        return array(
            'tests directory' => array(array('tests/FooTest.php' => '<?php'), 'forbidden directories'),
            '.env file' => array(array('.env' => 'X=1'), 'forbidden files'),
            'npmrc' => array(array('assets/admin/.npmrc' => 'x'), 'forbidden files'),
            'source map' => array(array('assets/build/app.js.map' => '{}'), 'source maps'),
            'composer.json' => array(array('composer.json' => '{}'), 'forbidden files'),
            'unknown top-level entry' => array(array('docs/guide.md' => 'x'), 'top-level entry not allowed'),
            'unknown assets entry' => array(array('assets/blocks/src/index.ts' => 'x'), 'not allowed below assets/'),
            'dev dependency' => array(array('vendor/phpunit/phpunit/phpunit.php' => '<?php'), 'development dependencies'),
            'private key' => array(
                array('assets/build/key.txt' => "-----BEGIN RSA PRIVATE KEY-----\nabc\n"),
                'possible secrets',
            ),
            'developer path' => array(array('assets/build/x.js' => 'var p="/home/stefan/project";'), 'absolute developer paths'),
            'php syntax error' => array(array('packages/core/src/Broken.php' => '<?php function ('), 'PHP syntax error'),
            'missing .mo' => array(array('languages/sw-gallery-de_DE.mo' => null), 'sw-gallery-de_DE.mo'),
            'missing block build' => array(array('assets/blocks/build/index.js' => null), 'assets/blocks/build/index.js'),
            'manifest references missing file' => array(
                array('assets/build/.vite/manifest.json' => '{"a":{"file":"gone.js"}}'),
                'references missing files',
            ),
            'version differs from constant' => array(
                array('sw-gallery.php' => "<?php\n/**\n * Version: 1.0.0\n * Plugin URI: https://github.com/x/y\n */\ndefine( 'SWGALLERY_VERSION', '1.0.1' );\n"),
                'SWGALLERY_VERSION',
            ),
            'placeholder plugin uri' => array(
                array('sw-gallery.php' => "<?php\n/**\n * Version: 1.0.0\n * Plugin URI: https://example.com/x\n */\ndefine( 'SWGALLERY_VERSION', '1.0.0' );\n"),
                'Plugin URI',
            ),
        );
    }

    /**
     * @param array<string, string|null> $mutations
     */
    #[DataProvider('badPackages')]
    public function testBadPackageIsRejected(array $mutations, string $expectedMessage): void
    {
        [$code, $output] = $this->verify($this->zip($mutations));
        self::assertSame(1, $code, $output);
        self::assertStringContainsString($expectedMessage, $output);
    }

    public function testWrongFileNameAndSizeBudgetAreRejected(): void
    {
        [$code, $output] = $this->verify($this->zip(array(), 'plugin.zip'));
        self::assertSame(1, $code);
        self::assertStringContainsString('does not match', $output);

        [$code, $output] = $this->verify($this->zip(array()), 'SWG_ZIP_MAX_BYTES=100 ');
        self::assertSame(1, $code);
        self::assertStringContainsString('budget', $output);
    }

    public function testEntriesOutsideThePluginFolderAreRejected(): void
    {
        $root = $this->tmp . '/stray';
        mkdir($root, 0777, true);
        file_put_contents($root . '/evil.php', '<?php');
        $zip = $this->tmp . '/sw-gallery-1.0.0.zip';
        exec('cd ' . escapeshellarg($root) . ' && zip -q ' . escapeshellarg($zip) . ' evil.php');
        [$code, $output] = $this->verify($zip);
        self::assertSame(1, $code);
        self::assertStringContainsString('outside sw-gallery/', $output);
    }

    /**
     * Builds a valid minimal package tree, applies the mutations (null deletes a file) and zips it.
     *
     * @param array<string, string|null> $mutations
     */
    private function zip(array $mutations, string $name = 'sw-gallery-1.0.0.zip'): string
    {
        $hash = md5('assets/blocks/build/index.js');
        $files = array(
            'sw-gallery.php' => "<?php\n/**\n * Version: 1.0.0\n * Plugin URI: https://github.com/x/y\n */\ndefine( 'SWGALLERY_VERSION', '1.0.0' );\n",
            'uninstall.php' => '<?php',
            'CHANGELOG.md' => "# Changelog\n\n## [1.0.0] - 2026-01-01\n",
            'vendor/autoload.php' => '<?php class_alias( \\stdClass::class, "SWGallery\\\\WordPress\\\\Plugin" );'
                . ' class_alias( \\stdClass::class, "SWGallery\\\\Core\\\\Domain\\\\Gallery\\\\Gallery" );',
            'assets/blocks/block.json' => '{}',
            'assets/blocks/build/index.js' => 'x',
            'assets/media-library/media-library.js' => 'x',
            'assets/admin/.vite/manifest.json' => '{"a":{"file":"app.js"}}',
            'assets/admin/app.js' => 'x',
            'assets/build/.vite/manifest.json' => '{"a":{"file":"l.js","css":["l.css"]}}',
            'assets/build/l.js' => 'x',
            'assets/build/l.css' => 'x',
            'languages/sw-gallery.pot' => '',
            'languages/sw-gallery-de_DE.po' => '',
            'languages/sw-gallery-de_DE.mo' => 'x',
            'languages/sw-gallery-de_DE-' . $hash . '.json' => '{}',
        );
        foreach ($mutations as $path => $content) {
            if ($content === null) {
                unset($files[$path]);
            } else {
                $files[$path] = $content;
            }
        }

        $root = $this->tmp . '/tree-' . bin2hex(random_bytes(3));
        foreach ($files as $path => $content) {
            $file = $root . '/sw-gallery/' . $path;
            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), 0777, true);
            }
            file_put_contents($file, $content);
        }
        $zip = $this->tmp . '/' . $name;
        @unlink($zip);
        exec('cd ' . escapeshellarg($root) . ' && zip -qr ' . escapeshellarg($zip) . ' sw-gallery');

        return $zip;
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function verify(string $zip, string $env = ''): array
    {
        $output = array();
        exec($env . escapeshellarg(dirname(__DIR__, 2) . '/bin/verify-zip.sh') . ' ' . escapeshellarg($zip) . ' 2>&1', $output, $code);

        return array($code, implode("\n", $output));
    }
}
