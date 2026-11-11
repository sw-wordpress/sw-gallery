<?php
/**
 * WordPress entry of the render pipeline: the one place the shortcode, block, PHP API, preview and feed surfaces call.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- core value objects use camelCase properties (CODE-STYLE 3.1).

namespace SWGallery\WordPress\Rendering;

use SWGallery\Core\Application\Render\EntryPoint;
use SWGallery\Core\Application\Render\RenderContext;
use SWGallery\Core\Application\Render\RenderGallery;
use SWGallery\Core\Application\Render\RenderResult;
use SWGallery\Core\Contract\GalleryRepositoryInterface;
use SWGallery\Core\Domain\Access\Actor;
use SWGallery\Core\Domain\Access\RenderAccess;
use SWGallery\Core\Domain\Gallery\GallerySettings;
use SWGallery\WordPress\Security\WpGalleryAccessPolicy;

/**
 * Builds the {@see RenderContext} from the current request (user, password cookie, instance numbering), runs the pipeline
 * and turns its outcome into final markup: the core password form for protected galleries, editor-only diagnostics
 * before the gallery. It enqueues nothing; callers read {@see RenderResult::$assets}.
 *
 * Options (all optional, all untrusted and sanitised here): `page`, `per_page`, `layout`, `settings` (array),
 * `eager` (bool, default true).
 */
final class GalleryRenderer {

	/** @var array<int, int> Embeds rendered so far in this request, per gallery id. */
	private array $instances = array();

	private int $embeds = 0;

	public function __construct(
		private readonly RenderGallery $pipeline,
		private readonly GalleryRepositoryInterface $galleries,
		private readonly WpGalleryAccessPolicy $access
	) {
	}

	/**
	 * @param int                  $gallery_id Gallery id.
	 * @param EntryPoint           $entry      The calling surface.
	 * @param array<string, mixed> $options    See the class description.
	 */
	public function render( int $gallery_id, EntryPoint $entry, array $options = array() ): RenderResult {
		if ( $gallery_id <= 0 ) {
			return new RenderResult( '', RenderAccess::Nothing );
		}
		$context = $this->context( $gallery_id, $entry, $options );
		$result  = $this->pipeline->render( $context );

		if ( ! $entry->isInteractive() ) {
			return $result->needsPasswordForm() ? $result->withHtml( '' ) : $result;
		}
		if ( $result->needsPasswordForm() ) {
			return $result->withHtml( get_the_password_form( $gallery_id ) );
		}
		if ( array() === $result->diagnostics ) {
			return $result;
		}
		return $result->withHtml( DiagnosticMarkup::render( $result->diagnostics ) . $result->html );
	}

	/**
	 * Convenience for callers that only need the markup.
	 *
	 * @param int                  $gallery_id Gallery id.
	 * @param EntryPoint           $entry      The calling surface.
	 * @param array<string, mixed> $options    See the class description.
	 */
	public function html( int $gallery_id, EntryPoint $entry, array $options = array() ): string {
		return $this->render( $gallery_id, $entry, $options )->html;
	}

	/**
	 * @param int                  $gallery_id Gallery id.
	 * @param EntryPoint           $entry      The calling surface.
	 * @param array<string, mixed> $options    Untrusted embed options.
	 */
	private function context( int $gallery_id, EntryPoint $entry, array $options ): RenderContext {
		$user     = get_current_user_id();
		$gallery  = $this->galleries->find( $gallery_id );
		$unlocked = null === $gallery || $this->access->isPasswordUnlocked( $gallery );

		$number                         = ( $this->instances[ $gallery_id ] ?? 0 ) + 1;
		$this->instances[ $gallery_id ] = $number;
		$first_embed                    = 0 === $this->embeds;
		++$this->embeds;

		$layout   = isset( $options['layout'] ) && is_string( $options['layout'] ) && 1 === preg_match( GallerySettings::LAYOUT_ID_PATTERN, $options['layout'] ) ? $options['layout'] : null;
		$settings = array();
		if ( isset( $options['settings'] ) && is_array( $options['settings'] ) ) {
			foreach ( $options['settings'] as $key => $value ) {
				$settings[ (string) $key ] = $value;
			}
		}

		return new RenderContext(
			$gallery_id,
			$entry,
			new Actor( max( 0, $user ) ),
			sprintf( 'swg-%d-%d', $gallery_id, $number ),
			max( 1, $this->int_option( $options, 'page', 1 ) ),
			max( 1, min( RenderContext::MAX_PER_PAGE, $this->int_option( $options, 'per_page', RenderContext::DEFAULT_PER_PAGE ) ) ),
			$layout,
			$settings,
			$unlocked,
			! isset( $options['eager'] ) || (bool) $options['eager'],
			$first_embed
		);
	}

	/**
	 * @param array<string, mixed> $options Untrusted embed options.
	 */
	private function int_option( array $options, string $key, int $fallback ): int {
		$value = $options[ $key ] ?? null;
		return is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ? (int) $value : $fallback;
	}
}
