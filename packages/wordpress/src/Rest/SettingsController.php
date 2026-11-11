<?php
/**
 * Settings routes of swg/v1: read and partially update the plugin settings.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Rest;

use SWGallery\WordPress\Settings\ConfirmationRequiredException;
use SWGallery\WordPress\Settings\PluginSettings;

/**
 * `manage_options` only. PATCH is partial and last-write-wins (settings change rarely, so there is no
 * `expectedRevision`). Switching `deleteDataOnUninstall` on needs `confirmDeleteData: true` in the same request.
 */
final class SettingsController extends AbstractController {

	public const CODE_CONFIRMATION_REQUIRED = 'swg_confirmation_required';
	public const CODE_NO_CHANGES            = 'swg_no_changes';

	/**
	 * REST base of the route.
	 *
	 * @var string
	 */
	protected $rest_base = 'settings';

	public function __construct( ErrorMapper $errors, private readonly PluginSettings $settings ) {
		parent::__construct( $errors );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE_V1,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => $this->update_args(),
				),
			)
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return true|\WP_Error
	 */
	public function get_item_permissions_check( $request ) {
		return $this->require_capability( 'manage_options' );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return true|\WP_Error
	 */
	public function update_item_permissions_check( $request ) {
		return $this->require_capability( 'manage_options' );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_item( $request ) {
		return $this->guard( fn(): \WP_REST_Response => new \WP_REST_Response( $this->present( $this->settings->get() ) ) );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_item( $request ) {
		try {
			$params  = $request->get_params();
			$changes = array_intersect_key( $params, PluginSettings::DEFAULTS );
			if ( array() === $changes ) {
				return ErrorMapper::bad_request( self::CODE_NO_CHANGES, __( 'The request does not change anything.', 'sw-gallery' ) );
			}
			$confirmed = isset( $params['confirmDeleteData'] ) && true === $params['confirmDeleteData'];
			$updated   = $this->settings->update( $changes, $confirmed );
			return new \WP_REST_Response( $this->present( $updated ) );
		} catch ( ConfirmationRequiredException ) {
			return ErrorMapper::unprocessable(
				self::CODE_CONFIRMATION_REQUIRED,
				__( 'Confirm explicitly that galleries and their image relations may be deleted when the plugin is deleted.', 'sw-gallery' )
			);
		} catch ( \Throwable $error ) {
			return $this->errors->map( $error, $this->actor() );
		}
	}

	/**
	 * @param array<string, mixed> $values Effective settings.
	 * @return array<string, mixed>
	 */
	private function present( array $values ): array {
		return $values + array(
			'schemaVersion'    => PluginSettings::SCHEMA_VERSION,
			'defaults'         => PluginSettings::DEFAULTS,
			'deleteDataNotice' => __( 'When the plugin is deleted, all galleries and their image relations are removed. Images in the Media Library are never deleted.', 'sw-gallery' ),
		);
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function update_args(): array {
		return $this->validated(
			array(
				'defaultLayout'         => array(
					'description'       => 'Id of the layout new galleries start with (must be registered).',
					'type'              => 'string',
					'minLength'         => 1,
					'maxLength'         => 64,
					'sanitize_callback' => array( self::class, 'sanitize_line' ),
				),
				'itemsPerPage'          => array(
					'description' => 'Frontend page size of galleries.',
					'type'        => 'integer',
					'minimum'     => PluginSettings::MIN_ITEMS_PER_PAGE,
					'maximum'     => PluginSettings::MAX_ITEMS_PER_PAGE,
				),
				'lightbox'              => array(
					'description' => 'Open images in the lightbox.',
					'type'        => 'boolean',
				),
				'deleteDataOnUninstall' => array(
					'description' => 'Delete galleries and relations (never attachments) when the plugin is deleted; turning it on needs confirmDeleteData.',
					'type'        => 'boolean',
				),
				'confirmDeleteData'     => array(
					'description' => 'Explicit confirmation required together with deleteDataOnUninstall: true.',
					'type'        => 'boolean',
					'default'     => false,
				),
			)
		);
	}
}
