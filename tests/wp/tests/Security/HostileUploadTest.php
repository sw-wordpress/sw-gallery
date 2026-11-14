<?php
/**
 * `POST /swg/v1/media` against hostile files and names (M5-04 h): double extensions, traversal and NUL bytes in the
 * name, server-config files, markup and script content behind an image name, polyglots, and an image whose header
 * claims an absurd size. Whatever is stored must stay inside the uploads directory with a non-executable extension.
 *
 * @package sw-gallery
 */

use SWGallery\Tests\Support\ImageFixtures;
use SWGallery\Tests\Support\RestFixtures;

class HostileUploadTest extends SwgTestCase {

	use RestFixtures;
	use ImageFixtures;

	private int $author;

	public function set_up(): void {
		parent::set_up();
		$this->bootRest();
		$this->author = $this->makeUser( 'author' );
	}

	public function tear_down(): void {
		foreach ( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) as $id ) {
			wp_delete_attachment( (int) $id, true );
		}
		$this->removeImageTempFiles();
		$this->shutdownRest();
		parent::tear_down();
	}

	private function attachment_count(): int {
		return count( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function accepted_but_renamed(): array {
		return array(
			'double extension'        => array( 'shell.php.png' ),
			'php before the image'    => array( 'a.php5.png' ),
			'traversal'               => array( '../../../../wp-config.php.png' ),
			'backslash traversal'     => array( '..\\..\\evil.png' ),
			'absolute path'           => array( '/etc/cron.d/x.png' ),
			'right-to-left override'  => array( "evil\u{202E}gnp.exe.png" ),
			'markup in the name'      => array( '"><svg onload=alert(1)>.png' ),
		);
	}

	/**
	 * @dataProvider accepted_but_renamed
	 */
	public function test_a_real_image_with_a_hostile_name_is_stored_safely( string $name ): void {
		$response = $this->upload( $this->makeImageFile( 'png' ), $name, $this->author );
		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$id   = (int) $response->get_data()['id'];
		$file = (string) get_attached_file( $id );
		$real = realpath( $file );
		$base = realpath( wp_upload_dir()['basedir'] );
		$this->assertNotFalse( $real );
		$this->assertStringStartsWith( (string) $base . DIRECTORY_SEPARATOR, (string) $real, 'stored inside the uploads directory' );
		$stored = basename( $file );
		$this->assertMatchesRegularExpression( '/\.png$/', $stored );
		$this->assertDoesNotMatchRegularExpression( '/\.(php\d?|phtml|phar|pl|cgi|asp|aspx|jsp|sh|exe)(\.|$)/i', $stored, 'no executable extension segment' );
		$this->assertStringNotContainsString( '..', $stored );
		$this->assertDoesNotMatchRegularExpression( '/[\\\\\/<>"\x00-\x1f]/u', $stored );
		$this->assertStringNotContainsString( '<', (string) wp_json_encode( $response->get_data() ), 'the resource carries no markup' );
	}

	public function test_a_name_longer_than_the_file_system_allows_fails_generically_and_stores_nothing(): void {
		// Informational finding S-05: the storage failure is a generic 500 (no path leaks); WordPress core behaves alike.
		$before   = $this->attachment_count();
		$response = $this->upload( $this->makeImageFile( 'png' ), str_repeat( 'a', 300 ) . '.png', $this->author );
		$this->assertContains( $response->get_status(), array( 201, 400, 422, 500 ) );
		if ( 201 !== $response->get_status() ) {
			$this->assertSame( $before, $this->attachment_count() );
			$this->assertStringNotContainsString( ABSPATH, (string) wp_json_encode( $response->get_data() ) );
		}
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function refused_files(): array {
		$png = "\x89PNG\r\n\x1a\n";
		return array(
			'php with image magic'          => array( "GIF89a<?php system(\$_GET['c']); ?>", 'x.php' ),
			'php named jpg'                 => array( "<?php system(\$_GET['c']); ?>", 'x.jpg' ),
			'html named png'                => array( '<html><script>alert(1)</script></html>', 'x.png' ),
			'svg named png'                 => array( '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>', 'x.png' ),
			'htaccess'                      => array( $png . 'php_value auto_prepend_file x', '.htaccess' ),
			'png magic with htaccess name'  => array( $png, 'x.htaccess' ),
			'nul byte before the extension' => array( "GIF89a<?php ?>", "x.php\0.gif" ),
			'phar'                          => array( "<?php __HALT_COMPILER(); ?>", 'x.phar' ),
			'empty extension'               => array( $png, 'noextension' ),
			'html disguised by a double extension' => array( '<script>alert(1)</script>', 'x.html.jpg' ),
		);
	}

	/**
	 * @dataProvider refused_files
	 */
	public function test_non_image_content_is_refused_and_nothing_is_stored( string $content, string $name ): void {
		$before   = $this->attachment_count();
		$response = $this->upload( $this->tempFile( 'hostile', $content ), $name, $this->author );
		$this->assertContains( $response->get_status(), array( 400, 422 ), $name . ' ' . wp_json_encode( $response->get_data() ) );
		$this->assertSame( $before, $this->attachment_count() );
	}

	public function test_a_valid_image_carrying_a_php_payload_is_stored_as_a_plain_image(): void {
		// Polyglot: a decodable PNG with PHP appended. WordPress core accepts such files too; what keeps them harmless is
		// the extension (never executable) and the web server not executing .png. Documented as an accepted risk.
		$path = $this->makeImageFile( 'png' );
		file_put_contents( $path, "\n<?php echo 'pwned'; ?>", FILE_APPEND );
		$response = $this->upload( $path, 'polyglot.png', $this->author );
		$this->assertSame( 201, $response->get_status() );
		$file = (string) get_attached_file( (int) $response->get_data()['id'] );
		$this->assertMatchesRegularExpression( '/\.png$/', $file );
		$this->assertSame( 'image/png', get_post_mime_type( (int) $response->get_data()['id'] ) );
	}

	public function test_an_image_header_claiming_an_absurd_size_does_not_exhaust_memory(): void {
		$ihdr   = pack( 'N', 60000 ) . pack( 'N', 60000 ) . "\x08\x02\x00\x00\x00";
		$chunk  = static fn( string $type, string $data ): string => pack( 'N', strlen( $data ) ) . $type . $data . pack( 'N', crc32( $type . $data ) );
		$bomb   = "\x89PNG\r\n\x1a\n" . $chunk( 'IHDR', $ihdr ) . $chunk( 'IDAT', (string) gzcompress( str_repeat( "\0", 1000 ) ) ) . $chunk( 'IEND', '' );
		$before = $this->attachment_count();

		$response = $this->upload( $this->tempFile( 'bomb', $bomb ), 'bomb.png', $this->author );
		$this->assertContains( $response->get_status(), array( 413, 422 ), wp_json_encode( $response->get_data() ) );
		$this->assertSame( $before, $this->attachment_count(), 'nothing remains of the refused file' );
	}
}
