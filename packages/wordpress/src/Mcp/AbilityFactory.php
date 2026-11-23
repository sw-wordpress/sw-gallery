<?php
/**
 * Registers ability definitions and wraps their callbacks (docs/mcp-harness.md §6). The only place that calls
 * `wp_register_ability`.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- the framework independent core's value objects expose camelCase properties (CODE-STYLE 3.1).

namespace SWGallery\WordPress\Mcp;

use SWGallery\Core\Domain\Access\Actor;
use SWGallery\Core\Exception\AccessDeniedException;
use SWGallery\WordPress\Rest\ErrorMapper;

/**
 * Every definition runs through the same wrapper: tier and login first, then the definition's own permission check,
 * failures mapped to the REST error codes (no exception text), output size capped, audit written. A definition can not
 * bypass it.
 */
final class AbilityFactory {

	public const NAMESPACE_SLUG = 'sw-gallery';
	public const CATEGORY       = 'sw-gallery';

	public const CODE_TIER_DISABLED = 'swg_mcp_tier_disabled';
	public const CODE_TOO_LARGE     = 'swg_mcp_output_too_large';

	public const MAX_OUTPUT_CONSTANT = 'SW_GALLERY_MCP_MAX_OUTPUT';
	public const DEFAULT_MAX_OUTPUT  = 204800;

	public function __construct( private readonly ErrorMapper $errors, private readonly McpAuditLog $audit ) {
	}

	/** Registers the ability when its tier is allowed; returns the registered ability id or null. */
	public function register( AbilityDefinition $definition ): ?string {
		if ( ! TierPolicy::allows( $definition->tier() ) ) {
			return null;
		}
		$id = self::NAMESPACE_SLUG . '/' . $definition->name();
		if ( $definition->annotations()['destructive'] ) {
			add_filter( 'wp_ability_validate_input', array( DestructiveInput::class, 'validate' ), 10, 3 );
		}
		wp_register_ability(
			$id,
			array(
				'label'               => $definition->label(),
				'description'         => $definition->description(),
				'category'            => self::CATEGORY,
				'input_schema'        => $definition->inputSchema(),
				'output_schema'       => $definition->outputSchema(),
				'permission_callback' => function ( $input = null ) use ( $definition ) {
					return $this->check( $definition, is_array( $input ) ? $input : array() );
				},
				'execute_callback'    => function ( $input = null ) use ( $definition ) {
					return $this->run( $definition, is_array( $input ) ? $input : array() );
				},
				'meta'                => array(
					'annotations'  => $definition->annotations(),
					'show_in_rest' => false,
					'mcp'          => array( 'public' => true ),
				),
			)
		);
		return $id;
	}

	/**
	 * @param AbilityDefinition    $definition The ability.
	 * @param array<string, mixed> $input      Validated input.
	 * @return true|\WP_Error Never `false`.
	 */
	private function check( AbilityDefinition $definition, array $input ): true|\WP_Error {
		$actor = $this->actor();
		if ( ! TierPolicy::allows( $definition->tier() ) ) {
			return $this->denied( $definition, $actor, new \WP_Error( self::CODE_TIER_DISABLED, __( 'This action is disabled by the MCP access level of the site.', 'sw-gallery' ), array( 'status' => 403 ) ) );
		}
		if ( $actor->isAnonymous() ) {
			return $this->denied( $definition, $actor, $this->errors->map( new AccessDeniedException(), $actor ) );
		}
		try {
			$result = $definition->permission( $input );
		} catch ( \Throwable $error ) {
			$result = $this->errors->map( $error, $actor );
		}
		if ( true === $result ) {
			return true;
		}
		return $this->denied( $definition, $actor, $result );
	}

	/**
	 * @param AbilityDefinition    $definition The ability.
	 * @param array<string, mixed> $input      Validated input.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function run( AbilityDefinition $definition, array $input ): array|\WP_Error {
		$actor = $this->actor();
		try {
			$result = $definition->execute( $input );
		} catch ( \Throwable $error ) {
			$result = $this->errors->map( $error, $actor );
		}
		if ( $result instanceof \WP_Error ) {
			$this->audit->record( $definition->name(), $actor->userId, McpAuditLog::ERROR, (string) $result->get_error_code() );
			return $result;
		}
		if ( strlen( (string) wp_json_encode( $result ) ) > self::max_output() ) {
			$this->audit->record( $definition->name(), $actor->userId, McpAuditLog::ERROR, self::CODE_TOO_LARGE );
			return new \WP_Error( self::CODE_TOO_LARGE, __( 'The result is too large to return.', 'sw-gallery' ), array( 'status' => 413 ) );
		}
		if ( ! $definition->annotations()['readonly'] ) {
			$this->audit->record( $definition->name(), $actor->userId, McpAuditLog::OK, 'ok' );
		}
		return $result;
	}

	private function denied( AbilityDefinition $definition, Actor $actor, \WP_Error $error ): \WP_Error {
		$this->audit->record( $definition->name(), $actor->userId, McpAuditLog::DENIED, (string) $error->get_error_code() );
		return $error;
	}

	private function actor(): Actor {
		return new Actor( max( 0, get_current_user_id() ) );
	}

	public static function max_output(): int {
		$value = defined( self::MAX_OUTPUT_CONSTANT ) ? constant( self::MAX_OUTPUT_CONSTANT ) : self::DEFAULT_MAX_OUTPUT;
		return is_int( $value ) && $value > 0 ? $value : self::DEFAULT_MAX_OUTPUT;
	}
}
