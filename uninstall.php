<?php
/**
 * Uninstall handler. User data is preserved unless the administrator explicitly opted into removal.
 *
 * @package sw-gallery
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$swgallery_autoload = defined( 'SWGALLERY_AUTOLOADER' ) ? SWGALLERY_AUTOLOADER : __DIR__ . '/vendor/autoload.php';
$swgallery_loader   = require $swgallery_autoload;
$swgallery_loader->addPsr4( 'SWGallery\\Core\\', __DIR__ . '/packages/core/src/' );
$swgallery_loader->addPsr4( 'SWGallery\\WordPress\\', __DIR__ . '/packages/wordpress/src/' );
unset( $swgallery_autoload, $swgallery_loader );

\SWGallery\WordPress\Lifecycle::uninstall();
