<?php

declare(strict_types=1);

namespace SWGallery\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SWGallery\Core\Application\Service\ImportProviderRegistry;
use SWGallery\Tests\Support\InMemoryImportProvider;

final class ImportProviderRegistryTest extends TestCase
{
    public function testRegisterGetHasAndOrder(): void
    {
        $registry = new ImportProviderRegistry();
        $a = new InMemoryImportProvider('a');
        $b = new InMemoryImportProvider('b');
        self::assertSame([], $registry->all());
        self::assertFalse($registry->has('a'));

        $registry->register($b);
        $registry->register($a);

        self::assertTrue($registry->has('a'));
        self::assertSame($a, $registry->get('a'));
        self::assertSame([$b, $a], $registry->all());
    }

    public function testDuplicateIdIsRejected(): void
    {
        $registry = new ImportProviderRegistry();
        $registry->register(new InMemoryImportProvider('a'));
        $this->expectException(\InvalidArgumentException::class);
        $registry->register(new InMemoryImportProvider('a'));
    }

    public function testEmptyIdIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ImportProviderRegistry())->register(new InMemoryImportProvider(''));
    }

    public function testUnknownProviderThrowsOutOfBounds(): void
    {
        $this->expectException(\OutOfBoundsException::class);
        (new ImportProviderRegistry())->get('missing');
    }
}
