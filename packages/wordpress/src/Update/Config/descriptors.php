<?php
/**
 * Local plugin identities. No requests or option reads.
 *
 * @package sw-gallery
 */

return array(
	// uninstall.php runs without the plugin's constants.
	defined( 'SWGALLERY_FILE' ) ? plugin_basename( SWGALLERY_FILE ) : 'sw-gallery/sw-gallery.php' => array(
		'slug'    => 'sw-gallery',
		'version' => defined( 'SWGALLERY_VERSION' ) ? SWGALLERY_VERSION : '0',
	),
);
