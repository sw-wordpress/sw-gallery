<?php

declare(strict_types=1);

namespace SWGallery\Tests\Support;

use SWGallery\Core\Contract\GalleryLayoutInterface;
use SWGallery\Core\Contract\ImageHintsProviderInterface;
use SWGallery\Core\Domain\Gallery\GallerySettings;
use SWGallery\Core\Domain\Layout\ImageHints;
use SWGallery\Core\Domain\Layout\LayoutRenderRequest;

/** Layout double recording its request; renders one marker per item. */
final class RecordingLayout implements GalleryLayoutInterface, ImageHintsProviderInterface
{
    public ?LayoutRenderRequest $last = null;

    /**
     * @param array<string, mixed> $schema
     */
    public function __construct(
        private readonly string $id = 'grid',
        private readonly array $schema = ['type' => 'object', 'properties' => ['columns' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 12]]],
        private readonly ImageHints $hints = new ImageHints(300, '50vw', 2),
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getLabel(): string
    {
        return $this->id;
    }

    public function getSettingsSchema(): array
    {
        return $this->schema;
    }

    public function imageHints(GallerySettings $settings): ImageHints
    {
        return $this->hints;
    }

    public function render(LayoutRenderRequest $request): string
    {
        $this->last = $request;

        return '<div data-layout="' . $this->id . '">' . count($request->items) . '</div>';
    }
}
