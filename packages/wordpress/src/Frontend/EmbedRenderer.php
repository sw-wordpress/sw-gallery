<?php
/**
 * Renders an embed and reports it to the asset collector.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Frontend;

use SWGallery\Core\Application\Render\EntryPoint;
use SWGallery\WordPress\Rendering\GalleryRenderer;

/**
 * The call every public output surface makes: the one renderer plus the collector bookkeeping, so assets are
 * enqueued only for galleries that were really printed.
 */
final class EmbedRenderer {

	public function __construct(
		private readonly GalleryRenderer $renderer,
		private readonly AssetCollector $collector
	) {
	}

	/**
	 * @param int                  $gallery_id Gallery id.
	 * @param EntryPoint           $entry      The calling surface.
	 * @param array<string, mixed> $options    Sanitised render options.
	 * @return string Final markup (escaped by the renderer).
	 */
	public function render( int $gallery_id, EntryPoint $entry, array $options = array() ): string {
		$result = $this->renderer->render( $gallery_id, $entry, $options );
		$this->collector->record( $gallery_id, $result );
		return $result->html;
	}
}
