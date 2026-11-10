<?php

declare(strict_types=1);

namespace SWGallery\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards the translation workflow: every string in the code is in the .pot, every shipped language translates all of
 * them and the compiled catalogs are current. After changing user-facing strings run: bin/i18n.sh update.
 */
final class I18nTest extends TestCase
{
    public function testCatalogsAreCompleteAndUpToDate(): void
    {
        exec('which xgettext msgfmt msgmerge 2>/dev/null', $tools, $code);
        if ($code !== 0 || count($tools) < 3) {
            self::markTestSkipped('GNU gettext is not installed.');
        }
        exec(escapeshellarg(dirname(__DIR__, 2) . '/bin/i18n.sh') . ' check 2>&1', $output, $status);
        self::assertSame(0, $status, implode("\n", array_slice($output, -5)));
    }
}
