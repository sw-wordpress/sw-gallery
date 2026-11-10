<?php

declare(strict_types=1);

namespace SWGallery\Tests\Support;

use SWGallery\Core\Contract\GalleryLayoutInterface;
use SWGallery\Core\Domain\Layout\LayoutRenderRequest;

/** Layout double with a configurable settings schema. */
final class StubLayout implements GalleryLayoutInterface
{
    /**
     * @param array<string, mixed> $schema
     */
    public function __construct(
        private readonly string $id = 'grid',
        private readonly array $schema = ['type' => 'object'],
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getLabel(): string
    {
        return ucfirst($this->id);
    }

    public function getSettingsSchema(): array
    {
        return $this->schema;
    }

    public function render(LayoutRenderRequest $request): string
    {
        return '<div></div>';
    }
}
