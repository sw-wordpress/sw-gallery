<?php

declare(strict_types=1);

namespace SWGallery\Tests\Support;

use SWGallery\Core\Contract\GalleryLayoutInterface;
use SWGallery\Core\Contract\ImageHintsProviderInterface;
use SWGallery\Core\Domain\Gallery\GallerySettings;
use SWGallery\Core\Domain\Layout\ImageHints;
use SWGallery\Core\Domain\Layout\LayoutRenderRequest;

/** Third-party layout double that throws from one chosen method (`id`, `schema`, `hints` or `render`). */
final class FailingLayout implements GalleryLayoutInterface, ImageHintsProviderInterface
{
    /** @var array<string, mixed> */
    private array $schema = ['type' => 'object', 'properties' => []];

    public function __construct(private readonly string $failsIn, private readonly string $id = 'broken')
    {
    }

    public function getId(): string
    {
        $this->maybeFail('id');

        return $this->id;
    }

    public function getLabel(): string
    {
        return 'Broken';
    }

    public function getSettingsSchema(): array
    {
        if ($this->failsIn === 'schema-late' && $this->calls++ > 0) {
            throw new \RuntimeException('secret-schema');
        }
        $this->maybeFail('schema');

        return $this->schema;
    }

    public function imageHints(GallerySettings $settings): ImageHints
    {
        $this->maybeFail('hints');

        return new ImageHints(300, '50vw', 2);
    }

    public function render(LayoutRenderRequest $request): string
    {
        $this->maybeFail('render');

        return '<div class="broken"></div>';
    }

    private int $calls = 0;

    private function maybeFail(string $where): void
    {
        if ($this->failsIn === $where) {
            throw new \RuntimeException('secret-' . $where);
        }
    }
}
