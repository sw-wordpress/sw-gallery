<?php

declare(strict_types=1);

namespace SWGallery\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Process-local constants and disabled extensions must fail closed without booting a real WordPress. */
final class UpdateRuntimeBoundaryTest extends TestCase
{
    /** @return array<string,array{string,list<string>,string}> */
    public static function contexts(): array
    {
        return [
            'CLI' => ['WP_CLI', [], 'updates_forbidden'],
            'REST' => ['REST_REQUEST', [], 'updates_forbidden'],
            'no ZIP extension' => ['', ['-n'], 'updates_not_configured'],
            'no Sodium verifier' => ['', ['-d', 'disable_functions=sodium_crypto_sign_verify_detached'], 'updates_not_configured'],
        ];
    }

    /** @param list<string> $options */
    #[DataProvider('contexts')]
    public function testUnsupportedRuntimeNeverReachesTransport(string $constant, array $options, string $expected): void
    {
        $script = <<<'CODE'
final class WP_Error {
    public function __construct(public string $code, public string $message) {}
}
function __(string $message, string $domain): string { return $message; }
function is_admin(): bool { return true; }
function wp_doing_cron(): bool { return false; }
function is_multisite(): bool { return false; }
function current_user_can(string $capability): bool { return true; }
function wp_is_file_mod_allowed(string $context): bool { return true; }
function apply_filters(string $name, mixed $value): mixed { return $value; }
function wp_safe_remote_get(): never { throw new RuntimeException('Unexpected HTTP.'); }
define('SWGALLERY_RELEASE_PUBLIC_KEYS', ['fixture' => base64_encode(str_repeat('x', 32))]);
if ($argv[2] !== '') { define($argv[2], true); }
require $argv[1] . '/packages/wordpress/src/Update/Service.php';
$result = \SWGallery\WordPress\Update\Service::check(true);
if (!$result instanceof WP_Error) { exit(2); }
echo $result->code;
CODE;
        $command = array_merge([PHP_BINARY], $options, ['-r', $script, dirname(__DIR__, 2), $constant]);
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        self::assertSame(0, proc_close($process), $output);
        self::assertSame($expected, $output);
    }
}
