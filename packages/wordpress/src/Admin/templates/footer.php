<?php
/**
 * Shared author credit for the plugin admin footer.
 *
 * @package sw-gallery
 */

defined( 'ABSPATH' ) || exit;

echo '<span id="swg-author-credit" hidden>';
esc_html_e( 'Open source by Stefan Warnat – free to use, free to change, better together.', 'sw-gallery' );
echo '</span>';
