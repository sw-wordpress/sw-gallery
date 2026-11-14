<?php
/**
 * Raised instead of writing when the database schema was created by a newer plugin version.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Database;

final class SchemaNewerException extends \RuntimeException {

	public const CODE = 'swg_schema_newer';

	public function __construct() {
		parent::__construct( 'The database schema is newer than this plugin version.' );
	}
}
