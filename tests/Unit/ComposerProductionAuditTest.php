<?php

declare(strict_types=1);

namespace SWGallery\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ComposerProductionAuditTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/swg-audit-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    public function testAnExplicitlyEmptyProductionListNeedsNoComposerOrNetwork(): void
    {
        file_put_contents($this->dir . '/composer.lock', '{"packages":[],"packages-dev":[{"name":"dev/tool"}]}');
        [$code, $output] = $this->runAudit([]);
        self::assertSame(0, $code, $output);
        self::assertStringContainsString('No production Composer packages to audit.', $output);
        [$code, $output] = $this->runAudit(['--format=json']);
        self::assertSame(0, $code, $output);
        self::assertSame([], json_decode($output, true, 512, JSON_THROW_ON_ERROR)['advisories']);
    }

    /** @return iterable<string, array{string|null}> */
    public static function invalidLocks(): iterable
    {
        yield 'missing' => [null];
        yield 'invalid JSON' => ['{'];
        yield 'missing packages' => ['{}'];
        yield 'object packages' => ['{"packages":{}}'];
        yield 'wrong packages type' => ['{"packages":"empty"}'];
        yield 'not a list' => ['{"packages":{"name":"vendor/package"}}'];
    }

    #[DataProvider('invalidLocks')]
    public function testMissingOrMalformedLockNeverPasses(?string $lock): void
    {
        if ($lock !== null) {
            file_put_contents($this->dir . '/composer.lock', $lock);
        }
        [$code, $output] = $this->runAudit([]);
        self::assertSame(1, $code, $output);
        self::assertStringContainsString('Composer production audit failed:', $output);
    }

    public function testNonemptyAuditsKeepArgumentsAndPropagateEveryExitCode(): void
    {
        file_put_contents($this->dir . '/composer.lock', '{"packages":[{"name":"vendor/package"}]}');
        foreach ([0, 1, 2, 3] as $expected) {
            file_put_contents($this->dir . '/composer', "#!/bin/sh\nprintf '%s\\n' \"\$@\"\nexit $expected\n");
            chmod($this->dir . '/composer', 0700);
            [$code, $output] = $this->runAudit(['--format=json']);
            self::assertSame($expected, $code, $output);
            self::assertSame("audit\n--locked\n--no-dev\n--format=json", trim($output));
        }
    }

    /**
     * @param list<string> $arguments
     * @return array{int, string}
     */
    private function runAudit(array $arguments): array
    {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/bin/audit-composer-production.php', ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes,
            $this->dir,
            ['PATH' => $this->dir . PATH_SEPARATOR . getenv('PATH')]
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        return [proc_close($process), $output];
    }
}
