<?php

declare(strict_types=1);

namespace SWGallery\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** The version lives in the plugin header and in SWGALLERY_VERSION (cache busting); both must never drift apart. */
final class PluginVersionTest extends TestCase
{
    public function testHeaderVersionEqualsConstant(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/sw-gallery.php');

        self::assertSame(1, preg_match('/^\s*\*\s*Version:\s*(\S+)\s*$/m', $source, $header), 'Plugin header has no Version.');
        self::assertSame(
            1,
            preg_match("/define\\(\\s*'SWGALLERY_VERSION',\\s*'([^']+)'\\s*\\)/", $source, $constant),
            'SWGALLERY_VERSION is not defined with a literal.'
        );
        self::assertSame($header[1], $constant[1]);
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/', $header[1]);
    }
}
