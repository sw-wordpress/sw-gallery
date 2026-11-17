<?php

declare(strict_types=1);

namespace SWGallery\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** Copy/paste operator commands run only with disposable keys, a fake GitHub CLI and a private bare Git repo. */
final class ReleaseOperationsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/swg-operations-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isDir() && !$file->isLink()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($this->dir);
    }

    /** @param list<string> $command @return array{int,string} */
    private function command(array $command): array
    {
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $this->dir);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        return [proc_close($process), $output];
    }

    /** @return array<string,array{string}> */
    public static function shells(): array
    {
        return ['bash' => ['bash'], 'zsh' => ['zsh']];
    }

    #[DataProvider('shells')]
    public function testDocumentedKeyCommandsAreSafeWithDisposableKeysAndMockedGithub(string $shell): void
    {
        $root = dirname(__DIR__, 2);
        $doc = (string) file_get_contents($root . '/docs/GITHUB-UPDATES-BETRIEB.md');
        preg_match_all('/```bash\n(.*?)\n```/s', $doc, $matches);
        self::assertCount(3, $matches[1]);
        $plugin = $this->dir . '/plugin';
        $ring = $plugin . '/packages/wordpress/src/Update/Config/trusted-keys.php';
        mkdir(dirname($ring), 0700, true);
        $old = base64_encode(random_bytes(32));
        file_put_contents($ring, "<?php return ['existing' => '$old'];\n");
        $this->command(['git', 'init', $plugin]);
        $this->command(['git', '-C', $plugin, 'add', '.']);
        $this->command(['git', '-C', $plugin, '-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.invalid', '-c', 'commit.gpgsign=false', 'commit', '-m', 'Fixture']);
        file_put_contents($this->dir . '/gh', <<<'SH'
#!/bin/sh
set -eu
case "$1 $2" in
  'auth status'|'secret list'|'variable list') exit 0 ;;
  'secret set') key=$(cat); test "${#key}" = 88; exit 0 ;;
  'variable set') exit 0 ;;
  *) exit 90 ;;
esac
SH
        );
        chmod($this->dir . '/gh', 0700);
        $blocks = array_map(fn(string $block): string => str_replace(
            ['/var/www/html/wordpress_dev/wp-content/plugins/sw-gallery', '$HOME/.local/share/sw-gallery-release-keys'],
            [$plugin, $this->dir . '/keys'],
            $block
        ), $matches[1]);
        foreach ($blocks as $block) {
            [$exit, $output] = $this->command(['env', 'PATH=' . $this->dir . ':' . getenv('PATH'), $shell, '-c', $block]);
            self::assertSame(0, $exit, $output);
        }
        self::assertSame(0700, fileperms($this->dir . '/keys') & 0777);
        self::assertSame(0600, fileperms($this->dir . '/keys/signing-key.base64') & 0777);
        $keys = require $ring;
        self::assertSame($old, $keys['existing']);
        self::assertCount(2, $keys);
        $secret = base64_decode(trim((string) file_get_contents($this->dir . '/keys/signing-key.base64')), true);
        self::assertSame(64, strlen($secret));
        self::assertStringNotContainsString(base64_encode($secret), (string) file_get_contents($ring));
        [$exit] = $this->command([$shell, '-c', $blocks[0]]);
        self::assertNotSame(0, $exit, 'Must never overwrite existing private keys.');
        $incoming = require $this->dir . '/keys/public-keys.php';
        $id = array_key_first($incoming);
        file_put_contents($ring, "<?php return " . var_export([$id => base64_encode(random_bytes(32))], true) . ';');
        [$exit] = $this->command([$shell, '-c', $blocks[1]]);
        self::assertNotSame(0, $exit, 'Must reject conflicting public key IDs.');
    }

    public function testMakeReleaseUsesOnlyAnExplicitVersionAndAnIsolatedOrigin(): void
    {
        $source = dirname(__DIR__, 2);
        mkdir($this->dir . '/bin');
        foreach (['Makefile', 'bin/release.sh', 'bin/version.sh'] as $file) {
            copy($source . '/' . $file, $this->dir . '/' . $file);
        }
        chmod($this->dir . '/bin/version.sh', 0700);
        file_put_contents($this->dir . '/sw-gallery.php', "<?php\n/**\n * Version:           0.1.0\n */\ndefine( 'SWGALLERY_VERSION', '0.1.0' );\n");
        file_put_contents($this->dir . '/CHANGELOG.md', "# Changelog\n\n## [Unreleased]\n\n- Fixture\n\n## [0.1.0] - 2026-01-01\n");
        foreach (['apps/admin', 'assets', 'assets/blocks', 'e2e'] as $project) {
            mkdir($this->dir . '/' . $project, 0700, true);
            file_put_contents($this->dir . '/' . $project . '/package.json', '{"version":"0.1.0"}');
            file_put_contents($this->dir . '/' . $project . '/package-lock.json', '{"version":"0.1.0","packages":{"":{"version":"0.1.0"}}}');
        }
        foreach ([['git','init','--bare','origin.git'],['git','init','-b','main'],['git','config','user.name','Fixture'],['git','config','user.email','fixture@example.invalid'],['git','config','commit.gpgsign','false'],['git','config','tag.gpgsign','false']] as $command) {
            $this->command($command);
        }
        file_put_contents($this->dir . '/.gitignore', "origin.git/\n");
        $this->command(['git','add','.']);
        $this->command(['git','commit','-m','Fixture']);
        $this->command(['git','remote','add','origin',$this->dir . '/origin.git']);
        [$exit,$output] = $this->command(['make','release','VERSION=0.1.1']);
        self::assertSame(0, $exit, $output);
        self::assertStringContainsString("'0.1.1'", (string) file_get_contents($this->dir . '/sw-gallery.php'));
        [$exit,$output] = $this->command(['git','--git-dir=origin.git','show-ref','--verify','refs/tags/0.1.1']);
        self::assertSame(0, $exit, $output);
        foreach (['', '0.1.0', '0.1.1', '0.2.0-rc.1'] as $version) {
            [$exit] = $this->command(['make','release','VERSION=' . $version]);
            self::assertNotSame(0, $exit);
        }
    }
}
