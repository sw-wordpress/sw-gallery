<?php

/**
 * PHPStan-only constants defined at runtime by sw-gallery.php and WordPress core.
 *
 * @package sw-gallery
 */

define('SWGALLERY_VERSION', '0.0.0');
define('SWGALLERY_FILE', __DIR__ . '/../../sw-gallery.php');
define('SWGALLERY_DIR', __DIR__ . '/../../');
define('SWGALLERY_URL', 'https://example.test/wp-content/plugins/sw-gallery/');
define('SWGALLERY_MIN_PHP', '8.2');

if ( ! defined( 'DB_NAME' ) ) {
	define( 'DB_NAME', 'swg_phpstan' );
}
