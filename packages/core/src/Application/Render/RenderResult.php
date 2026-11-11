<?php

declare(strict_types=1);

namespace SWGallery\Core\Application\Render;

use SWGallery\Core\Domain\Access\RenderAccess;

/** Outcome of one render call. `html` is final markup (escaped by the layout) or empty. */
final class RenderResult
{
    /**
     * @param list<RenderDiagnostic> $diagnostics Editor-only messages (never filled for visitors or feeds).
     * @param list<RenderAsset>      $assets      Frontend assets the html needs.
     */
    public function __construct(
        public readonly string $html,
        public readonly RenderAccess $access,
        public readonly array $diagnostics = [],
        public readonly array $assets = [],
        public readonly int $page = 1,
        public readonly int $totalPages = 0,
        public readonly int $totalItems = 0,
        public readonly int $renderedItems = 0,
        public readonly ?string $layoutId = null,
    ) {
    }

    /** True when the adapter has to show the password form instead of the gallery. */
    public function needsPasswordForm(): bool
    {
        return $this->access === RenderAccess::PasswordForm;
    }

    public function withHtml(string $html): self
    {
        return new self(
            $html,
            $this->access,
            $this->diagnostics,
            $this->assets,
            $this->page,
            $this->totalPages,
            $this->totalItems,
            $this->renderedItems,
            $this->layoutId
        );
    }
}
