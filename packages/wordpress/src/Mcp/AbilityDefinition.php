<?php
/**
 * Contract of one MCP ability (docs/mcp-harness.md §6).
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Mcp;

interface AbilityDefinition {

	/**
	 * Verb-noun name without the namespace, for example `get-gallery`.
	 *
	 * @return lowercase-string&non-falsy-string
	 */
	public function name(): string;

	/** One of the {@see TierPolicy} tiers above `off`. */
	public function tier(): string;

	/** Translated label. */
	public function label(): string;

	/** Machine-facing English description (not translated, static, no user data). */
	public function description(): string;

	/** @return array<string, mixed> JSON Schema, `type: object`, `additionalProperties: false`. */
	public function inputSchema(): array;

	/** @return array<string, mixed> JSON Schema of the result. */
	public function outputSchema(): array;

	/** @return array{readonly: bool, destructive: bool, idempotent: bool} */
	public function annotations(): array;

	/**
	 * Capability and object checks (tier and login are checked by the factory).
	 *
	 * @param array<string, mixed> $input Validated input.
	 */
	public function permission( array $input ): true|\WP_Error;

	/**
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute( array $input ): array|\WP_Error;
}
