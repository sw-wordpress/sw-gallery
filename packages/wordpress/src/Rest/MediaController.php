<?php
/**
 * Media routes of swg/v1: permission-filtered image search and native upload.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- the framework independent core's value objects expose camelCase properties (CODE-STYLE 3.1).

namespace SWGallery\WordPress\Rest;

use SWGallery\WordPress\Media\MediaLister;
use SWGallery\WordPress\Repository\WpdbGalleryRelations;
use SWGallery\WordPress\Security\WpGalleryAccessPolicy;

/**
 * Decisions D6 and D11: the Media Overview lists the images the actor may use (own uploads plus readable ones) and
 * shows how often each is used; the only write is the upload. Attachments are never edited or deleted here.
 */
final class MediaController extends AbstractController {

	public const DEFAULT_PER_PAGE = 40;

	/**
	 * REST base of the routes.
	 *
	 * @var string
	 */
	protected $rest_base = 'media';

	public function __construct(
		ErrorMapper $errors,
		private readonly MediaSearch $search,
		private readonly MediaUploader $uploader,
		private readonly MediaPresenter $presenter,
		private readonly MediaLister $lister
	) {
		parent::__construct( $errors );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE_V1,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => $this->collection_args(),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => $this->upload_args(),
				),
			)
		);
	}

	/**
	 * The Media Overview audience: it lists the Media Library and so stays on `upload_files` (assigning images by id does not, FU-16).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return true|\WP_Error
	 */
	public function get_items_permissions_check( $request ) {
		return $this->require_capability( 'upload_files' );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return true|\WP_Error
	 */
	public function create_item_permissions_check( $request ) {
		return $this->require_capability( 'upload_files' );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_items( $request ) {
		return $this->guard(
			function () use ( $request ): \WP_REST_Response {
				$per_page = $this->int_param( $request, 'per_page', self::DEFAULT_PER_PAGE );
				$found    = $this->lister->list(
					$this->actor(),
					(string) ( $request->get_param( 'search' ) ?? '' ),
					$this->int_param( $request, 'page', 1 ),
					$per_page,
					(string) ( $request->get_param( 'orderby' ) ?? 'date' ),
					(string) ( $request->get_param( 'order' ) ?? 'desc' ),
					$this->int_param( $request, 'uploadedBy', 0 )
				);
				$items    = $found['items'];
				return $this->with_pagination_headers( new \WP_REST_Response( $items ), $found['total'], $per_page );
			}
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_item( $request ) {
		try {
			return $this->upload_response( $request );
		} catch ( MediaUploadException $error ) {
			return new \WP_Error( $error->error_code, $error->getMessage(), array( 'status' => $error->status ) );
		} catch ( \Throwable $error ) {
			return $this->errors->map( $error, $this->actor() );
		}
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @throws MediaUploadException When the upload is refused or fails.
	 */
	private function upload_response( \WP_REST_Request $request ): \WP_REST_Response {
		$file = $request->get_file_params()['file'] ?? null;
		if ( ! is_array( $file ) ) {
			throw new MediaUploadException( MediaUploadException::MISSING_FILE, __( 'No file was uploaded.', 'sw-gallery' ), 400 );
		}
		$id   = $this->uploader->upload(
			$file,
			$this->actor()->userId,
			(string) ( $request->get_param( 'title' ) ?? '' ),
			(string) ( $request->get_param( 'alt' ) ?? '' )
		);
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post ) {
			throw new MediaUploadException( MediaUploadException::FAILED, __( 'The file could not be saved.', 'sw-gallery' ), 500 );
		}
		$this->search->prime_parents( array( $post ) );
		$response = new \WP_REST_Response( $this->presenter->present( $post, 0 ) );
		$response->set_status( 201 );
		return $response;
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function collection_args(): array {
		$pagination                        = $this->pagination_args();
		$pagination['per_page']['default'] = self::DEFAULT_PER_PAGE;

		return $this->validated(
			$pagination + array(
				'search'     => array(
					'description'       => 'Search text (title, caption, description).',
					'type'              => 'string',
					'maxLength'         => 200,
					'sanitize_callback' => array( self::class, 'sanitize_line' ),
				),
				'orderby'    => array(
					'description' => 'Sort field.',
					'type'        => 'string',
					'enum'        => array( 'date', 'title' ),
					'default'     => 'date',
				),
				'order'      => array(
					'description' => 'Sort direction.',
					'type'        => 'string',
					'enum'        => array( 'asc', 'desc' ),
					'default'     => 'desc',
				),
				'uploadedBy' => array(
					'description'       => 'Only images uploaded by this user (users without edit_others_posts always get their own).',
					'type'              => 'integer',
					'minimum'           => 1,
					'sanitize_callback' => 'absint',
				),
			)
		);
	}

	/**
	 * The file itself travels as multipart field `file` and is not a schema argument.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function upload_args(): array {
		return $this->validated(
			array(
				'title' => array(
					'description'       => 'Title of the attachment (default: the file name without extension).',
					'type'              => 'string',
					'maxLength'         => 200,
					'sanitize_callback' => array( self::class, 'sanitize_line' ),
				),
				'alt'   => array(
					'description'       => 'Alternative text.',
					'type'              => 'string',
					'maxLength'         => 500,
					'sanitize_callback' => array( self::class, 'sanitize_line' ),
				),
			)
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @param string           $name    Parameter name.
	 * @param int              $fallback Value when absent.
	 */
	private function int_param( \WP_REST_Request $request, string $name, int $fallback ): int {
		$value = $request->get_param( $name );
		return is_numeric( $value ) ? (int) $value : $fallback;
	}
}
