<?php
/**
 * POST /swg/v1/media: native WordPress upload handling, accepted and refused files, error mapping, clean-up.
 *
 * @package sw-gallery
 */

use SWGallery\Tests\Support\ImageFixtures;
use SWGallery\Tests\Support\RestFixtures;
use SWGallery\WordPress\Media\AllowedMimeTypes;

class RestMediaUploadTest extends SwgTestCase {

	use RestFixtures;
	use ImageFixtures;

	private int $author;

	public function set_up(): void {
		parent::set_up();
		$this->bootRest();
		$this->author = $this->makeUser( 'author' );
	}

	public function tear_down(): void {
		foreach ( get_posts(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
			)
		) as $id ) {
			wp_delete_attachment( (int) $id, true );
		}
		$this->removeImageTempFiles();
		$this->shutdownRest();
		parent::tear_down();
	}

	public function test_role_matrix(): void {
		$expected = array(
			'administrator' => 201,
			'editor'        => 201,
			'author'        => 201,
			'contributor'   => 403,
			'subscriber'    => 403,
			'anonymous'     => 401,
		);
		foreach ( $expected as $role => $status ) {
			$user     = 'anonymous' === $role ? 0 : $this->makeUser( $role );
			$before   = $this->attachmentCount();
			$response = $this->upload( $this->makeImageFile(), 'photo.jpg', $user );

			$this->assertSame( $status, $response->get_status(), $role );
			$this->assertSame( $before + ( 201 === $status ? 1 : 0 ), $this->attachmentCount(), $role . ': nothing is created for a refused user' );
		}
	}

	public function test_a_jpeg_is_stored_by_wordpress_with_sub_sizes_and_the_resource_shape(): void {
		$response = $this->upload( $this->makeImageFile( 'jpeg', 640, 480 ), 'My Holiday Photo.jpg', $this->author, array(), array( 'alt' => 'A beach' ) );
		$data     = $this->data( $response );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame(
			array( 'id', 'title', 'alt', 'caption', 'mimeType', 'width', 'height', 'thumbnailUrl', 'fullUrl', 'uploadedAt', 'uploadedBy', 'usedInGalleries' ),
			array_keys( $data )
		);
		$this->assertSame( 'My Holiday Photo', $data['title'] );
		$this->assertSame( 'A beach', $data['alt'] );
		$this->assertSame( 'image/jpeg', $data['mimeType'] );
		$this->assertSame( 640, $data['width'] );
		$this->assertSame( 480, $data['height'] );
		$this->assertSame( $this->author, $data['uploadedBy'] );
		$this->assertSame( 0, $data['usedInGalleries'] );
		$this->assertStringStartsWith( 'http', $data['fullUrl'] );
		$this->assertStringNotContainsString( ABSPATH, (string) wp_json_encode( $data ) );

		$id   = $data['id'];
		$meta = wp_get_attachment_metadata( $id );
		$this->assertArrayHasKey( 'thumbnail', $meta['sizes'], 'sub-sizes come from the WordPress pipeline' );
		$this->assertFileExists( (string) get_attached_file( $id ) );
		$this->assertSame( 'attachment', get_post_type( $id ) );
		$this->assertSame( (string) $this->author, get_post_field( 'post_author', $id ) );
	}

	public function test_title_and_alt_are_sanitized_and_bounded(): void {
		$data = $this->data( $this->upload( $this->makeImageFile(), 'x.jpg', $this->author, array(), array( 'title' => ' <b>Nice</b> day ' ) ) );
		$this->assertSame( 'Nice day', $data['title'] );

		$long = $this->upload( $this->makeImageFile(), 'x.jpg', $this->author, array(), array( 'title' => str_repeat( 'a', 201 ) ) );
		$this->assertSame( 400, $long->get_status() );
		$this->assertSame( 'rest_invalid_param', $long->as_error()->get_error_code() );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function accepted_formats(): array {
		return array(
			'png'  => array( 'png', 'a.png', 'image/png' ),
			'gif'  => array( 'gif', 'a.gif', 'image/gif' ),
			'webp' => array( 'webp', 'a.webp', 'image/webp' ),
			'jpeg with wrong extension' => array( 'png', 'really-a-png.jpg', 'image/png' ),
		);
	}

	/**
	 * @dataProvider accepted_formats
	 */
	public function test_allowed_raster_formats_are_accepted( string $format, string $name, string $mime ): void {
		if ( ! in_array( $mime, AllowedMimeTypes::all(), true ) ) {
			$this->markTestSkipped( $mime . ' is not supported by this server.' );
		}
		$response = $this->upload( $this->makeImageFile( $format ), $name, $this->author );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( $mime, $this->data( $response )['mimeType'] );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function refused_types(): array {
		return array(
			'svg'                  => array( '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"><rect width="1" height="1"/></svg>', 'vector.svg' ),
			'heic'                 => array( "\0\0\0\x18ftypheic\0\0\0\0mif1heic", 'photo.heic' ),
			'php disguised as jpg' => array( '<?php echo 1;', 'shell.jpg' ),
			'text'                 => array( 'just text', 'notes.txt' ),
			'pdf'                  => array( "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF", 'doc.pdf' ),
			'executable extension' => array( '<?php echo 1;', 'shell.php' ),
		);
	}

	/**
	 * @dataProvider refused_types
	 */
	public function test_other_file_types_are_refused_with_422_and_nothing_is_stored( string $content, string $name ): void {
		$files_before = $this->uploadedFileCount();
		$posts_before = $this->attachmentCount();

		$response = $this->upload( $this->tempFile( 'bad', $content ), $name, $this->author );

		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'swg_unsupported_media_type', $response->as_error()->get_error_code() );
		$this->assertSame( $files_before, $this->uploadedFileCount(), 'nothing was written to uploads' );
		$this->assertSame( $posts_before, $this->attachmentCount() );
		$this->assertNoLeak( $response );
	}

	public function test_a_file_with_an_image_signature_but_an_undecodable_body_is_refused(): void {
		$ihdr = pack( 'N', 10 ) . pack( 'N', 10 ) . "\x08\x02\x00\x00\x00";
		$png  = "\x89PNG\r\n\x1a\n" . pack( 'N', 13 ) . 'IHDR' . $ihdr . pack( 'N', crc32( 'IHDR' . $ihdr ) ) . 'garbage-not-a-png-body';
		$files_before = $this->uploadedFileCount();

		$response = $this->upload( $this->tempFile( 'corrupt', $png ), 'broken.png', $this->author );

		$this->assertSame( 422, $response->get_status() );
		$this->assertContains( $response->as_error()->get_error_code(), array( 'swg_invalid_image', 'swg_unsupported_media_type' ) );
		$this->assertSame( $files_before, $this->uploadedFileCount() );
	}

	public function test_a_file_with_only_a_jpeg_header_is_refused(): void {
		$response = $this->upload( $this->tempFile( 'jpeg', "\xFF\xD8\xFF\xE0\x00\x10JFIF" ), 'broken.jpg', $this->author );

		$this->assertSame( 422, $response->get_status() );
		$this->assertNoLeak( $response );
	}

	public function test_oversized_files_answer_413_before_anything_is_written(): void {
		$path = $this->makeImageFile( 'jpeg', 200, 200 );
		add_filter( 'upload_size_limit', static fn(): int => 500 );
		$files_before = $this->uploadedFileCount();

		$response = $this->upload( $path, 'big.jpg', $this->author );

		$this->assertSame( 413, $response->get_status() );
		$this->assertSame( 'swg_file_too_large', $response->as_error()->get_error_code() );
		$this->assertSame( $files_before, $this->uploadedFileCount() );
		$this->assertSame( 0, $this->attachmentCount() );
	}

	/**
	 * @return array<string, array{0: int, 1: int, 2: string}>
	 */
	public static function transfer_errors(): array {
		return array(
			'ini size'    => array( UPLOAD_ERR_INI_SIZE, 413, 'swg_file_too_large' ),
			'form size'   => array( UPLOAD_ERR_FORM_SIZE, 413, 'swg_file_too_large' ),
			'partial'     => array( UPLOAD_ERR_PARTIAL, 400, 'swg_upload_failed' ),
			'no file'     => array( UPLOAD_ERR_NO_FILE, 400, 'swg_missing_file' ),
			'no tmp dir'  => array( UPLOAD_ERR_NO_TMP_DIR, 500, 'swg_upload_failed' ),
			'cant write'  => array( UPLOAD_ERR_CANT_WRITE, 500, 'swg_upload_failed' ),
			'extension'   => array( UPLOAD_ERR_EXTENSION, 500, 'swg_upload_failed' ),
		);
	}

	/**
	 * @dataProvider transfer_errors
	 */
	public function test_php_upload_errors_are_mapped( int $error, int $status, string $code ): void {
		$response = $this->upload( $this->makeImageFile(), 'a.jpg', $this->author, array( 'error' => $error ) );

		$this->assertSame( $status, $response->get_status() );
		$this->assertSame( $code, $response->as_error()->get_error_code() );
		$this->assertNoLeak( $response );
	}

	public function test_a_request_without_a_file_is_a_400(): void {
		wp_set_current_user( $this->author );
		$request = new WP_REST_Request( 'POST', '/swg/v1/media' );
		$request->set_body_params( array( 'title' => 'No file' ) );

		$response = rest_do_request( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'swg_missing_file', $response->as_error()->get_error_code() );
	}

	public function test_malformed_file_entries_and_empty_files_are_400(): void {
		wp_set_current_user( $this->author );
		$request = new WP_REST_Request( 'POST', '/swg/v1/media' );
		$request->set_file_params( array( 'file' => array( 'name' => array( 'a' ), 'tmp_name' => 5 ) ) );
		$this->assertSame( 'swg_missing_file', rest_do_request( $request )->as_error()->get_error_code() );

		$empty = $this->upload( $this->tempFile( 'empty', '' ), 'empty.jpg', $this->author );
		$this->assertSame( 400, $empty->get_status() );
		$this->assertSame( 'swg_missing_file', $empty->as_error()->get_error_code() );

		$gone = $this->upload( '/nonexistent/tmp-file', 'gone.jpg', $this->author, array( 'size' => 100 ) );
		$this->assertSame( 400, $gone->get_status() );
		$this->assertNoLeak( $gone );
	}

	public function test_a_storage_failure_answers_a_generic_500_without_paths(): void {
		$path = $this->makeImageFile();
		// Core and extensions report storage problems with free text that may name directories; it must never reach the client.
		add_filter(
			'wp_handle_mock_upload_prefilter',
			static function ( array $file ): array {
				$file['error'] = 'Unable to create directory ' . ABSPATH . 'wp-content/uploads/2026/11.';
				return $file;
			}
		);

		$response = $this->upload( $path, 'a.jpg', $this->author );

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'swg_upload_failed', $response->as_error()->get_error_code() );
		$this->assertNoLeak( $response );
		$this->assertSame( 0, $this->attachmentCount() );
	}

	public function test_a_user_without_upload_files_is_refused_by_the_uploader_itself(): void {
		$subscriber = $this->makeUser( 'subscriber' );
		$uploader   = new \SWGallery\WordPress\Rest\MediaUploader();

		$this->expectException( \SWGallery\WordPress\Rest\MediaUploadException::class );
		$uploader->upload( array( 'name' => 'a.jpg' ), $subscriber );
	}

	public function test_the_uploaded_image_is_listed_for_its_uploader(): void {
		$id = $this->data( $this->upload( $this->makeImageFile(), 'listed.jpg', $this->author ) )['id'];

		$list = $this->data( $this->call( 'GET', '/media', array(), $this->author ) );

		$this->assertSame( array( $id ), array_column( $list, 'id' ) );
	}

	private function attachmentCount(): int {
		return count(
			get_posts(
				array(
					'post_type'   => 'attachment',
					'post_status' => 'any',
					'numberposts' => -1,
					'fields'      => 'ids',
				)
			)
		);
	}

	private function uploadedFileCount(): int {
		$dir = wp_upload_dir( null, false );
		return count( glob( $dir['path'] . '/*' ) ?: array() );
	}

	private function assertNoLeak( WP_REST_Response $response ): void {
		$body = (string) wp_json_encode( $response->get_data() );
		foreach ( array( ABSPATH, WP_CONTENT_DIR, sys_get_temp_dir(), 'swg-upload-', '/nonexistent' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $body );
		}
	}
}
