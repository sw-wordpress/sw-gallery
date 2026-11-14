<?php

declare(strict_types=1);

namespace SWGallery\Core\Application\Render;

use SWGallery\Core\Contract\GalleryAccessPolicyInterface;
use SWGallery\Core\Contract\GalleryLayoutInterface;
use SWGallery\Core\Contract\GalleryRepositoryInterface;
use SWGallery\Core\Contract\ImageHintsProviderInterface;
use SWGallery\Core\Contract\LayoutAssetsProviderInterface;
use SWGallery\Core\Contract\LayoutRegistryInterface;
use SWGallery\Core\Contract\LoggerInterface;
use SWGallery\Core\Contract\MediaReferenceRepositoryInterface;
use SWGallery\Core\Contract\RenderItemSourceInterface;
use SWGallery\Core\Contract\RevisionStoreInterface;
use SWGallery\Core\Contract\SimpleMarkupRendererInterface;
use SWGallery\Core\Contract\ThumbnailResolverInterface;
use SWGallery\Core\Domain\Access\RenderAccess;
use SWGallery\Core\Domain\Gallery\Gallery;
use SWGallery\Core\Domain\Gallery\GallerySettings;
use SWGallery\Core\Domain\Layout\ImageHints;
use SWGallery\Core\Domain\Layout\ImageRequest;
use SWGallery\Core\Domain\Layout\ImageRole;
use SWGallery\Core\Domain\Layout\LayoutRenderRequest;
use SWGallery\Core\Domain\Layout\LoadingStrategy;
use SWGallery\Core\Domain\Layout\RenderItem;
use SWGallery\Core\Domain\Settings\SettingsSchemaValidator;
use SWGallery\Core\Logging\LogLevel;

/**
 * The one rendering pipeline (SPECIFICATION 5): every entry point calls it, so access, paging, overrides and
 * image selection cannot differ between block, shortcode, PHP API, preview and feed.
 *
 * 1. load the gallery and its schema-versioned settings, 2. decide access (the policy's embedAccess is the only
 * decision), 3. load one bounded page of items, 4. resolve texts (local override over attachment value, never
 * written back), 5. resolve image variants and the loading strategy, 6. render through the registered layout.
 * Diagnostics exist only for users who can edit the gallery; visitors never learn why nothing is shown.
 */
final class RenderGallery
{
    /** Display width requested for lightbox images; the resolver applies its lightbox size policy. */
    public const LIGHTBOX_DISPLAY_WIDTH = 2560;

    public function __construct(
        private readonly GalleryRepositoryInterface $galleries,
        private readonly GalleryAccessPolicyInterface $access,
        private readonly RenderItemSourceInterface $items,
        private readonly MediaReferenceRepositoryInterface $media,
        private readonly ThumbnailResolverInterface $thumbnails,
        private readonly LayoutRegistryInterface $layouts,
        private readonly SimpleMarkupRendererInterface $simpleMarkup,
        private readonly SettingsSchemaValidator $validator = new SettingsSchemaValidator(),
        private readonly ?LoggerInterface $logger = null,
        private readonly ?RevisionStoreInterface $revisions = null,
    ) {
    }

    public function render(RenderContext $context): RenderResult
    {
        $gallery = $this->galleries->find($context->galleryId);
        if ($gallery === null) {
            return $this->denied(
                RenderAccess::Nothing,
                $context,
                $this->access->canCreate($context->actor) ? [new RenderDiagnostic(RenderDiagnostic::GALLERY_NOT_FOUND)] : []
            );
        }

        $decision = $context->entryPoint === EntryPoint::Preview
            ? $this->access->previewAccess($context->actor, $gallery)
            : $this->access->embedAccess($context->actor, $gallery, $context->passwordUnlocked);
        if ($decision !== RenderAccess::Render) {
            $diagnostics = $decision === RenderAccess::Diagnostic
                ? [new RenderDiagnostic(RenderDiagnostic::GALLERY_NOT_PUBLIC, ['status' => $gallery->status->value])]
                : [];

            return $this->denied($decision, $context, $diagnostics);
        }

        $diagnostics = [];
        $canEdit = $this->access->canEdit($context->actor, $gallery);

        $resolution = $this->layouts->resolve($context->layoutOverride ?? $gallery->settings->layout);
        $layout = $resolution->layout;
        $layoutId = $resolution->fellBack ? LayoutRegistryInterface::FALLBACK_ID : $resolution->requestedId;
        if ($resolution->fellBack) {
            $diagnostics[] = new RenderDiagnostic(RenderDiagnostic::UNKNOWN_LAYOUT, ['layout' => $resolution->requestedId]);
        }
        // A (third-party) layout that throws is contained: the grid fallback takes over, editors get a diagnostic.
        try {
            $settings = $this->effectiveSettings($gallery, $layout, $layoutId, $context, $diagnostics);
        } catch (\Throwable $e) {
            [$layout, $layoutId] = $this->failedLayout($layoutId, $e, $diagnostics);
            $settings = $gallery->settings->withLayout($layoutId);
        }
        $diagnosticsVisible = $canEdit && $context->entryPoint->isInteractive();

        $page = $this->loadPage($context, $settings);
        $referencesByAttachment = $this->media->findReferences(
            array_map(static fn ($item): int => $item->attachmentId, $page['items']->items)
        );

        try {
            $hints = $layout instanceof ImageHintsProviderInterface ? $layout->imageHints($settings) : ImageHints::fallback();
        } catch (\Throwable $e) {
            [$layout, $layoutId] = $this->failedLayout($layoutId, $e, $diagnostics);
            $settings = $gallery->settings->withLayout($layoutId);
            $hints = $layout instanceof ImageHintsProviderInterface ? $layout->imageHints($settings) : ImageHints::fallback();
        }
        $renderItems = [];
        $unavailable = 0;
        $offset = ($page['page'] - 1) * $context->perPage;
        foreach ($page['items']->items as $index => $item) {
            $reference = $referencesByAttachment[$item->attachmentId] ?? null;
            $thumbnail = $reference === null ? null : $this->thumbnails->resolve(
                new ImageRequest($item->attachmentId, $hints->displayWidth, $hints->sizes)
            );
            if ($reference === null || $thumbnail === null) {
                ++$unavailable;
                continue;
            }
            $renderItems[] = new RenderItem(
                $item->id,
                $item->attachmentId,
                $item->overrides->resolveTitle($reference->title),
                $item->overrides->resolveCaption($reference->caption),
                $item->overrides->resolveAlt($reference->alt),
                $thumbnail,
                $this->thumbnails->resolve(
                    new ImageRequest($item->attachmentId, self::LIGHTBOX_DISPLAY_WIDTH, '100vw', ImageRole::Lightbox)
                ),
                $this->loading(count($renderItems), $page['page'], $hints, $context),
                $offset + $index + 1,
                $page['items']->total,
                $item->settings,
            );
        }

        if ($unavailable > 0) {
            $diagnostics[] = new RenderDiagnostic(RenderDiagnostic::IMAGES_UNAVAILABLE, ['count' => $unavailable]);
        }
        if ($renderItems === []) {
            if ($page['items']->total === 0 && $unavailable === 0) {
                $diagnostics[] = new RenderDiagnostic(RenderDiagnostic::EMPTY_GALLERY);
            }

            return $this->result('', $diagnosticsVisible ? $diagnostics : [], [], $page, 0, $layoutId);
        }

        $revision = $this->revisions?->current($gallery->id)->value ?? 0;
        $request = new LayoutRenderRequest($gallery->id, $context->instanceId, $renderItems, $settings, $revision);
        if ($context->entryPoint->isInteractive()) {
            try {
                $html = $layout->render($request);
            } catch (\Throwable $e) {
                [$layout, $layoutId] = $this->failedLayout($layoutId, $e, $diagnostics);
                $html = $layout->render(
                    new LayoutRenderRequest($gallery->id, $context->instanceId, $renderItems, $gallery->settings->withLayout($layoutId), $revision)
                );
            }

            return $this->result(
                $html,
                $diagnosticsVisible ? $diagnostics : [],
                [RenderAsset::Lightbox, ...($layout instanceof LayoutAssetsProviderInterface ? $layout->renderAssets() : [])],
                $page,
                count($renderItems),
                $layoutId
            );
        }

        return $this->result(
            $this->simpleMarkup->render($request),
            [],
            [],
            $page,
            count($renderItems),
            $layoutId
        );
    }

    /**
     * Contains a failing layout: logs the failure (layout id and exception class only), adds the editor diagnostic and
     * returns the fallback layout. A failing fallback itself is not contained (a built-in defect must surface).
     *
     * @param list<RenderDiagnostic> $diagnostics
     *
     * @return array{0: GalleryLayoutInterface, 1: string}
     */
    private function failedLayout(string $layoutId, \Throwable $error, array &$diagnostics): array
    {
        if ($layoutId === LayoutRegistryInterface::FALLBACK_ID) {
            throw $error;
        }
        $this->logger?->log(LogLevel::Error, 'swg_layout_failed', ['layout' => $layoutId, 'exception' => $error::class]);
        $diagnostics[] = new RenderDiagnostic(RenderDiagnostic::LAYOUT_FAILED, ['layout' => $layoutId]);

        return [$this->layouts->get(LayoutRegistryInterface::FALLBACK_ID), LayoutRegistryInterface::FALLBACK_ID];
    }

    /**
     * Applies validated per-embed overrides on top of the stored settings; invalid overrides are ignored (the stored
     * settings are used) and reported to editors.
     *
     * @param list<RenderDiagnostic> $diagnostics
     */
    private function effectiveSettings(
        Gallery $gallery,
        GalleryLayoutInterface $layout,
        string $layoutId,
        RenderContext $context,
        array &$diagnostics
    ): GallerySettings {
        $settings = $gallery->settings->withLayout($layoutId);
        if ($context->settingsOverrides === []) {
            return $settings;
        }
        $merged = array_replace($settings->values, $context->settingsOverrides);
        $result = $this->validator->validate($layout->getSettingsSchema(), $merged);
        if (!$result->isValid()) {
            $diagnostics[] = new RenderDiagnostic(RenderDiagnostic::INVALID_OVERRIDE);

            return $settings;
        }

        return $settings->withValues($result->values);
    }

    /**
     * Loads the requested page; a page past the end is clamped to the last page (one extra query in that case only).
     *
     * @return array{items: RenderItemPage, page: int, totalPages: int}
     */
    private function loadPage(RenderContext $context, GallerySettings $settings): array
    {
        $perPage = $context->perPage;
        $page = $context->page;
        $items = $this->items->findRenderPage($context->galleryId, $settings->sortMode, ($page - 1) * $perPage, $perPage);
        $totalPages = (int) ceil($items->total / $perPage);
        if ($items->items === [] && $items->total > 0 && $page > $totalPages) {
            $page = $totalPages;
            $items = $this->items->findRenderPage($context->galleryId, $settings->sortMode, ($page - 1) * $perPage, $perPage);
        }

        return ['items' => $items, 'page' => $page, 'totalPages' => $totalPages];
    }

    /** First row eager, the very first image with high priority (never lazy: it is the likely LCP image). */
    private function loading(int $index, int $page, ImageHints $hints, RenderContext $context): LoadingStrategy
    {
        if (!$context->entryPoint->isInteractive() || !$context->eagerLoading || $page !== 1 || $index >= $hints->firstRowCount) {
            return LoadingStrategy::Lazy;
        }

        return $index === 0 && $context->highPriority ? LoadingStrategy::Priority : LoadingStrategy::Eager;
    }

    /**
     * @param list<RenderDiagnostic> $diagnostics
     */
    private function denied(RenderAccess $access, RenderContext $context, array $diagnostics): RenderResult
    {
        return new RenderResult('', $access, $context->entryPoint->isInteractive() ? $diagnostics : []);
    }

    /**
     * @param list<RenderDiagnostic>                                           $diagnostics
     * @param list<RenderAsset>                                                $assets
     * @param array{items: RenderItemPage, page: int, totalPages: int} $page
     */
    private function result(
        string $html,
        array $diagnostics,
        array $assets,
        array $page,
        int $rendered,
        string $layoutId
    ): RenderResult {
        return new RenderResult(
            $html,
            RenderAccess::Render,
            $diagnostics,
            $assets,
            $page['page'],
            $page['totalPages'],
            $page['items']->total,
            $rendered,
            $layoutId
        );
    }
}
