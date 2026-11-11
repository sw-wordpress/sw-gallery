<?php
/**
 * `wp swg render`: prints the markup of a gallery (development and acceptance tool).
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Cli;

use SWGallery\Core\Application\Render\EntryPoint;
use SWGallery\WordPress\Infrastructure\Services;
use SWGallery\WordPress\Rendering\GalleryRenderer;

/**
 * Runs the same renderer as the frontend surfaces for the user of the CLI process (anonymous unless `--user` is given),
 * so drafts, private galleries and password protection behave exactly like on the website.
 */
final class RenderCommand {

	private CliOutputInterface $out;

	private ?GalleryRenderer $renderer;

	public function __construct( ?GalleryRenderer $renderer = null, ?CliOutputInterface $out = null ) {
		$this->renderer = $renderer;
		$this->out      = $out ?? new WpCliOutput();
	}

	/**
	 * Prints the HTML of a gallery.
	 *
	 * ## OPTIONS
	 *
	 * <galleryId>
	 * : ID of the gallery.
	 *
	 * [--entry=<entry>]
	 * : Which entry point to simulate: the interactive markup (shortcode) or the simplified feed markup.
	 * ---
	 * default: shortcode
	 * options:
	 *   - shortcode
	 *   - feed
	 * ---
	 *
	 * [--page=<n>]
	 * : Page of items to render.
	 * ---
	 * default: 1
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp swg render 12
	 *     wp swg render 12 --entry=feed --user=editor
	 *
	 * @param array<string>        $args       Positional arguments: the gallery id.
	 * @param array<string, mixed> $assoc_args Named arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$id = $args[0] ?? '';
		if ( ! ctype_digit( $id ) || (int) $id < 1 ) {
			$this->out->error( __( 'The gallery id must be a positive whole number.', 'sw-gallery' ) );
			return;
		}
		$entry = $assoc_args['entry'] ?? 'shortcode';
		if ( 'shortcode' !== $entry && 'feed' !== $entry ) {
			$this->out->error( __( 'The entry must be "shortcode" or "feed".', 'sw-gallery' ) );
			return;
		}
		$page = $assoc_args['page'] ?? 1;
		if ( ! is_scalar( $page ) || ! ctype_digit( (string) $page ) || (int) $page < 1 ) {
			$this->out->error( __( 'The page must be a positive whole number.', 'sw-gallery' ) );
			return;
		}

		$renderer = $this->renderer ?? Services::gallery_renderer();
		$result   = $renderer->render(
			(int) $id,
			'feed' === $entry ? EntryPoint::Feed : EntryPoint::Shortcode,
			array( 'page' => (int) $page )
		);
		if ( '' === $result->html ) {
			$this->out->warning( __( 'Nothing to render for this gallery and user (not found, not visible or no usable images).', 'sw-gallery' ) );
			return;
		}
		$this->out->line( $result->html );
	}
}
