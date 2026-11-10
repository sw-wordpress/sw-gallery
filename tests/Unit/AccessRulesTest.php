<?php

declare(strict_types=1);

namespace SWGallery\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SWGallery\Core\Domain\Access\Actor;
use SWGallery\Core\Domain\Access\RenderAccess;
use SWGallery\Core\Domain\Access\StatusAccessRules;
use SWGallery\Core\Domain\Gallery\GalleryStatus;

final class AccessRulesTest extends TestCase
{
    /**
     * Decision table of D4: status, password, unlocked, canReadPrivate, canEdit => access.
     *
     * @return iterable<string, array{GalleryStatus, bool, bool, bool, bool, RenderAccess}>
     */
    public static function decisionTable(): iterable
    {
        yield 'publish renders' => [GalleryStatus::Publish, false, false, false, false, RenderAccess::Render];
        yield 'publish with password asks' => [GalleryStatus::Publish, true, false, false, false, RenderAccess::PasswordForm];
        yield 'publish password unlocked' => [GalleryStatus::Publish, true, true, false, false, RenderAccess::Render];
        yield 'publish password has no editor bypass' => [GalleryStatus::Publish, true, false, true, true, RenderAccess::PasswordForm];
        yield 'private without right' => [GalleryStatus::Private, false, false, false, false, RenderAccess::Nothing];
        yield 'private with read right' => [GalleryStatus::Private, false, false, true, false, RenderAccess::Render];
        yield 'private editor without read right' => [GalleryStatus::Private, false, false, false, true, RenderAccess::Diagnostic];
        yield 'draft visitor' => [GalleryStatus::Draft, false, false, false, false, RenderAccess::Nothing];
        yield 'draft editor' => [GalleryStatus::Draft, false, false, false, true, RenderAccess::Diagnostic];
        yield 'pending visitor' => [GalleryStatus::Pending, false, false, true, false, RenderAccess::Nothing];
        yield 'future editor' => [GalleryStatus::Future, false, false, false, true, RenderAccess::Diagnostic];
        yield 'trash visitor' => [GalleryStatus::Trash, false, false, true, false, RenderAccess::Nothing];
        yield 'trash editor' => [GalleryStatus::Trash, false, false, false, true, RenderAccess::Diagnostic];
    }

    #[DataProvider('decisionTable')]
    public function testDecisionTable(
        GalleryStatus $status,
        bool $password,
        bool $unlocked,
        bool $canReadPrivate,
        bool $canEdit,
        RenderAccess $expected
    ): void {
        self::assertSame(
            $expected,
            StatusAccessRules::forEmbed($status, $password, $unlocked, $canReadPrivate, $canEdit)
        );
    }

    public function testActor(): void
    {
        self::assertTrue(Actor::anonymous()->isAnonymous());
        self::assertFalse((new Actor(4))->isAnonymous());
        $this->expectException(\InvalidArgumentException::class);
        new Actor(-1);
    }
}
