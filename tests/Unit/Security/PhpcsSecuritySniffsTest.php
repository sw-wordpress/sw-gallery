<?php

declare(strict_types=1);

namespace SWGallery\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;

/**
 * M5-04 g: the WordPress coding standard run (`composer cs:wp`) must keep the security sniff families
 * (`WordPress.Security.*`, `WordPress.DB.*`) active. Only the listed exclusions exist, each with its reason in the XML.
 */
final class PhpcsSecuritySniffsTest extends TestCase
{
    /** Excluded security/DB sniffs and why a reviewer accepted them. */
    private const ALLOWED_EXCLUSIONS = [
        'WordPress.Security.EscapeOutput.ExceptionNotEscaped' => 'Exception messages are never echoed as HTML (REST maps them to translated messages).',
        'WordPress.DB.DirectDatabaseQuery.DirectQuery' => 'Only for Repository/*: the plugin owns the swg_gallery_items table.',
        'WordPress.DB.DirectDatabaseQuery.NoCaching' => 'Only for Repository/*: caching sits above the repository.',
    ];

    public function testTheWordPressRulesetIsIncludedAsAWholeAndSecurityExclusionsAreJustified(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/phpcs-wordpress.xml.dist');
        self::assertInstanceOf(\SimpleXMLElement::class, $xml);

        $wordpress = null;
        foreach ($xml->rule as $rule) {
            if ((string) $rule['ref'] === 'WordPress') {
                $wordpress = $rule;
            }
        }
        self::assertNotNull($wordpress, 'The full "WordPress" standard (which contains the security and DB sniffs) must be referenced.');

        $found = [];
        foreach ($xml->xpath('//exclude') ?: [] as $exclude) {
            $name = (string) $exclude['name'];
            if (str_starts_with($name, 'WordPress.Security') || str_starts_with($name, 'WordPress.DB')) {
                $found[] = $name;
            }
        }
        sort($found);
        $allowed = array_keys(self::ALLOWED_EXCLUSIONS);
        sort($allowed);
        self::assertSame($allowed, $found, 'A security or database sniff was excluded (or an allowed exclusion disappeared).');
    }

    public function testSecuritySniffsAreOnlyDisabledWithAReason(): void
    {
        // A scoped `phpcs:disable ... -- reason` stays allowed (reviewable); a disable without a reason is not.
        $root = dirname(__DIR__, 3);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/packages/wordpress/src', \FilesystemIterator::SKIP_DOTS));
        $offenders = [];
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            foreach (file($file->getPathname()) ?: [] as $number => $line) {
                if (preg_match('/phpcs:disable[^\n]*WordPress\.(Security|DB)\b/', $line) === 1 && !str_contains($line, ' -- ')) {
                    $offenders[] = substr($file->getPathname(), strlen($root) + 1) . ':' . ($number + 1);
                }
            }
        }
        self::assertSame([], $offenders, 'File-level disabling of a security sniff family is not allowed.');
    }
}
