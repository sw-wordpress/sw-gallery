<?php

declare(strict_types=1);

namespace SWGallery\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Permanent guard (M5-04 g): production PHP (packages, plugin file, uninstall.php) must not contain dangerous language
 * constructs or outbound-network/file primitives, except the justified entries of ALLOWED. Token based, so comments and
 * strings never trigger it and reformatting cannot hide a call. `bin/` is deliberately out of scope (AGENTS.md).
 *
 * A finding fails the test with `file:line construct`. Fix the code; only add an allowlist entry with a reason a
 * reviewer can check, and keep the entry exact (file + construct): a stale entry fails the test as well.
 */
final class DangerousCodeScanTest extends TestCase
{
    /** Functions that are never acceptable in production code unless listed in ALLOWED. */
    private const FORBIDDEN_CALLS = [
        'eval', 'create_function', 'assert', 'extract', 'parse_str', 'exec', 'system', 'passthru', 'shell_exec',
        'proc_open', 'popen', 'pcntl_exec', 'curl_init', 'curl_exec', 'curl_multi_exec', 'fsockopen', 'pfsockopen',
        'stream_socket_client', 'socket_connect', 'mail', 'wp_mail',
        'wp_remote_get', 'wp_remote_post', 'wp_remote_request', 'wp_remote_head', 'wp_safe_remote_get', 'wp_safe_remote_post',
        'wp_safe_remote_request', 'wp_safe_remote_head', 'download_url', 'file_put_contents', 'move_uploaded_file',
        'unlink', 'rmdir', 'rename', 'copy', 'symlink', 'link', 'chmod', 'chown', 'readfile', 'highlight_file', 'show_source',
        'phpinfo', 'putenv', 'ini_set', 'set_include_path', 'dl', 'unserialize', 'maybe_unserialize', 'simplexml_load_string',
        'simplexml_load_file', 'file', 'file_get_contents', 'fopen', 'fpassthru', 'mkdir', 'touch', 'tempnam', 'tmpfile',
    ];

    /** Variables that must not be read: input must come from the narrow sources WPCS already audits. */
    private const FORBIDDEN_VARIABLES = ['$_REQUEST', '$_ENV'];

    /**
     * Justified exceptions, key `path::construct`.
     *
     * @var array<string, string>
     */
    private const ALLOWED = [
        'packages/wordpress/src/Admin/AssetManifest.php::file_get_contents' => 'Reads the plugin-owned admin build manifest; the path is the build directory plus a fixed name (no user input, no URL).',
        'packages/wordpress/src/Frontend/FrontendManifest.php::file_get_contents' => 'Reads the plugin-owned frontend manifest after realpath containment inside the build directory; never a URL or user input.',
        'packages/wordpress/src/Import/MediaLibraryProvider.php::fopen' => 'Opens the upload file of a readable attachment for the import stream; the path comes from localPath() (realpath inside the uploads directory, no stream wrapper, no traversal).',
    ];

    /** Include/require of a variable path (everything else must be a literal or a plugin constant). */
    private const ALLOWED_VARIABLE_INCLUDES = [
        'sw-gallery.php' => 'Composer autoloader path built from the plugin constant SWGALLERY_DIR in the same file.',
        'uninstall.php' => 'Composer autoloader path: plugin directory or the SWGALLERY_AUTOLOADER test constant.',
    ];

    public function testProductionCodeContainsNoDangerousConstructs(): void
    {
        $findings = [];
        $used = [];
        foreach ($this->files() as $relative => $path) {
            foreach ($this->scan($relative, (string) file_get_contents($path)) as [$line, $construct]) {
                $key = $relative . '::' . $construct;
                if (isset(self::ALLOWED[$key])) {
                    $used[$key] = true;
                    continue;
                }
                $findings[] = sprintf('%s:%d %s', $relative, $line, $construct);
            }
        }
        self::assertSame([], $findings, "Dangerous constructs found. Remove them or justify an exact allowlist entry:\n" . implode("\n", $findings));

        $stale = array_diff(array_keys(self::ALLOWED), array_keys($used));
        self::assertSame([], array_values($stale), 'Stale allowlist entries must be removed.');
    }

    public function testAllowedVariableIncludesAreExactlyTheAutoloaderLines(): void
    {
        foreach (self::ALLOWED_VARIABLE_INCLUDES as $file => $reason) {
            $code = (string) file_get_contents(dirname(__DIR__, 3) . '/' . $file);
            self::assertSame(1, preg_match_all('/\b(?:require|include)(?:_once)?\s+\$/', $code), $file . ' (' . $reason . ')');
            self::assertMatchesRegularExpression('/\$swgallery_loader\s*=\s*require \$swgallery_autoload;/', $code);
        }
    }

    /**
     * @return iterable<string, array{0: string, 1: list<array{0: int, 1: string}>}>
     */
    public static function scannerCases(): iterable
    {
        yield 'eval' => ['<?php eval($x);', [[1, 'eval']]];
        yield 'backtick' => ["<?php \$x = `ls`;", [[1, 'backtick shell execution']]];
        yield 'extract' => ['<?php extract($_POST);', [[1, 'extract']]];
        yield 'string assert' => ['<?php assert("1==1");', [[1, 'assert']]];
        yield 'remote call' => ['<?php wp_remote_get($url);', [[1, 'wp_remote_get']]];
        yield 'unserialize' => ['<?php $a = unserialize($b, ["allowed_classes" => false]);', [[1, 'unserialize']]];
        yield 'method with same name is fine' => ['<?php $this->system(); A::exec(); function exec2() {}', []];
        yield 'request superglobal' => ['<?php $x = $_REQUEST["a"];', [[1, '$_REQUEST']]];
        yield 'variable include' => ['<?php include $path;', [[1, 'include with a variable path']]];
        yield 'variable require_once' => ['<?php require_once $dir . "/a.php";', [[1, 'require_once with a variable path']]];
        yield 'literal include' => ['<?php require_once __DIR__ . "/a.php"; require_once ABSPATH . "wp-admin/x.php";', []];
        yield 'comment and string are ignored' => ['<?php // eval($x); unserialize($y)' . "\n" . '$s = "exec(1)"; /* system() */', []];
        yield 'method named require is fine' => ['<?php class A { private function require(int $x) {} function b() { return $this->require(1); } }', []];
    }

    /**
     * @param list<array{0: int, 1: string}> $expected
     */
    #[DataProvider('scannerCases')]
    public function testTheScannerFindsWhatItClaimsToFind(string $code, array $expected): void
    {
        self::assertSame($expected, $this->scan('src/Example.php', $code));
    }

    /**
     * @return array<string, string> relative path => absolute path
     */
    private function files(): array
    {
        $root = dirname(__DIR__, 3);
        $files = ['sw-gallery.php' => $root . '/sw-gallery.php', 'uninstall.php' => $root . '/uninstall.php'];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/packages', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[substr($file->getPathname(), strlen($root) + 1)] = $file->getPathname();
            }
        }
        ksort($files);
        self::assertGreaterThan(100, count($files), 'The production files were not discovered.');

        return $files;
    }

    /**
     * Variable function calls (`$callback()`) are not flagged: every one in the code base invokes a typed `Closure` or
     * `callable` parameter (transaction runner, guard, hooks); a string function name cannot be resolved by tokens.
     *
     * @return list<array{0: int, 1: string}> line and construct of every finding
     */
    private function scan(string $relative, string $code): array
    {
        $tokens = token_get_all($code);
        $count = count($tokens);
        $forbidden = array_flip(self::FORBIDDEN_CALLS);
        $findings = [];
        $inBacktick = false;
        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i];
            if ($token === '`') {
                // Opening and closing backtick: report once per pair.
                $inBacktick = !$inBacktick;
                if ($inBacktick) {
                    $findings[] = [$this->line($tokens, $i), 'backtick shell execution'];
                }
                continue;
            }
            if (!is_array($token)) {
                continue;
            }
            [$id, $text, $line] = $token;
            if ($id === T_EVAL) {
                $findings[] = [$line, 'eval'];
            } elseif ($id === T_VARIABLE && in_array($text, self::FORBIDDEN_VARIABLES, true)) {
                $findings[] = [$line, $text];
            } elseif (in_array($id, [T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE], true)) {
                if (in_array($this->previous($tokens, $i), ['->', '?->', '::', 'function'], true)) {
                    continue;
                }
                if ($this->hasVariableArgument($tokens, $i) && !isset(self::ALLOWED_VARIABLE_INCLUDES[$relative])) {
                    $findings[] = [$line, strtolower($text) . ' with a variable path'];
                }
            } elseif ($id === T_STRING) {
                $name = strtolower($text);
                $isCall = $this->next($tokens, $i) === '('
                    && !in_array($this->previous($tokens, $i), ['->', '?->', '::', 'function', 'new', 'const'], true);
                if ($isCall && isset($forbidden[$name])) {
                    $findings[] = [$line, $name];
                }
            }
        }

        return $findings;
    }

    /** @param list<array{0: int, 1: string, 2: int}|string> $tokens */
    private function hasVariableArgument(array $tokens, int $index): bool
    {
        for ($i = $index + 1, $count = count($tokens); $i < $count && $tokens[$i] !== ';'; ++$i) {
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_VARIABLE) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array{0: int, 1: string, 2: int}|string> $tokens */
    private function next(array $tokens, int $index): string
    {
        for ($i = $index + 1, $count = count($tokens); $i < $count; ++$i) {
            if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
        }

        return '';
    }

    /** @param list<array{0: int, 1: string, 2: int}|string> $tokens */
    private function previous(array $tokens, int $index): string
    {
        for ($i = $index - 1; $i >= 0; --$i) {
            if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
        }

        return '';
    }

    /** @param list<array{0: int, 1: string, 2: int}|string> $tokens */
    private function line(array $tokens, int $index): int
    {
        for ($i = $index; $i >= 0; --$i) {
            if (is_array($tokens[$i])) {
                return $tokens[$i][2];
            }
        }

        return 1;
    }
}
