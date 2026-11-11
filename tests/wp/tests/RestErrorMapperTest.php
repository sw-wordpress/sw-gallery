<?php
/**
 * The REST error mapper: every core exception type gets its HTTP status and stable code, unexpected failures stay generic.
 *
 * @package sw-gallery
 */

use SWGallery\Core\Domain\Access\Actor;
use SWGallery\Core\Domain\Settings\ValidationError;
use SWGallery\Core\Exception\AccessDeniedException;
use SWGallery\Core\Exception\GalleryNotFoundException;
use SWGallery\Core\Exception\ImportProviderException;
use SWGallery\Core\Exception\InvalidOrderException;
use SWGallery\Core\Exception\InvalidStateException;
use SWGallery\Core\Exception\ItemNotFoundException;
use SWGallery\Core\Exception\OperationVetoedException;
use SWGallery\Core\Exception\RevisionConflictException;
use SWGallery\Core\Exception\UnknownLayoutException;
use SWGallery\Core\Exception\ValidationFailedException;
use SWGallery\Tests\Support\RecordingLogger;
use SWGallery\WordPress\Rest\ErrorMapper;

class RestErrorMapperTest extends SwgTestCase {

	private RecordingLogger $logger;
	private ErrorMapper $mapper;

	public function set_up(): void {
		parent::set_up();
		$this->logger = new RecordingLogger();
		$this->mapper = new ErrorMapper( $this->logger );
	}

	/**
	 * @return array<string, array{0: Throwable, 1: int, 2: string}>
	 */
	public static function domain_errors(): array {
		return array(
			'gallery not found' => array( new GalleryNotFoundException(), 404, 'swg_gallery_not_found' ),
			'item not found'    => array( new ItemNotFoundException(), 404, 'swg_item_not_found' ),
			'access denied'     => array( new AccessDeniedException(), 403, 'swg_forbidden' ),
			'conflict'          => array( new RevisionConflictException( 2, 5 ), 409, 'swg_revision_conflict' ),
			'invalid state'     => array( new InvalidStateException(), 409, 'swg_invalid_state' ),
			'vetoed'            => array( new OperationVetoedException( 'locked' ), 403, 'swg_operation_vetoed' ),
			'invalid order'     => array( new InvalidOrderException(), 422, 'swg_invalid_order' ),
			'unknown layout'    => array( new UnknownLayoutException(), 422, 'swg_unknown_layout' ),
			'validation'        => array( new ValidationFailedException( array( new ValidationError( 'type', '/columns', 'Must be an integer.' ) ) ), 422, 'swg_validation_failed' ),
			'domain validation' => array( new InvalidArgumentException( 'Invalid layout id. secret /var/www' ), 422, 'swg_invalid_request' ),
		);
	}

	/**
	 * @dataProvider domain_errors
	 */
	public function test_domain_errors_get_status_and_stable_code( Throwable $error, int $status, string $code ): void {
		$result = $this->mapper->map( $error, new Actor( 7 ) );

		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( $status, $result->get_error_data()['status'] );
		$this->assertSame( array(), $this->logger->entries );
		$this->assertStringNotContainsString( '/var/www', $result->get_error_message() );
	}

	public function test_access_denied_is_401_for_anonymous_actors(): void {
		$result = $this->mapper->map( new AccessDeniedException(), Actor::anonymous() );

		$this->assertSame( 401, $result->get_error_data()['status'] );
		$this->assertSame( 'swg_forbidden', $result->get_error_code() );
	}

	public function test_a_conflict_carries_both_revisions(): void {
		$data = $this->mapper->map( new RevisionConflictException( 2, 5 ), new Actor( 7 ) )->get_error_data();

		$this->assertSame( 2, $data['expectedRevision'] );
		$this->assertSame( 5, $data['currentRevision'] );
	}

	public function test_a_veto_carries_the_safe_reason(): void {
		$this->assertSame( 'locked', $this->mapper->map( new OperationVetoedException( 'locked' ), new Actor( 7 ) )->get_error_data()['reason'] );
	}

	public function test_validation_failures_list_paths_and_codes(): void {
		$error  = new ValidationFailedException(
			array(
				new ValidationError( 'type', '/columns', 'Must be an integer.' ),
				new ValidationError( 'enum', '/gap', 'Not allowed.' ),
			)
		);
		$errors = $this->mapper->map( $error, new Actor( 7 ) )->get_error_data()['errors'];

		$this->assertSame( 'type', $errors[0]['code'] );
		$this->assertSame( '/columns', $errors[0]['path'] );
		$this->assertSame( '/gap', $errors[1]['path'] );
		$this->assertCount( 2, $errors );
	}

	public function test_domain_messages_are_translated(): void {
		$switched = switch_to_locale( 'de_DE' );
		add_filter( 'locale', static fn(): string => 'de_DE' );
		load_textdomain( 'sw-gallery', SWGALLERY_DIR . 'languages/sw-gallery-de_DE.mo' );
		try {
			$message = $this->mapper->map( new GalleryNotFoundException(), new Actor( 7 ) )->get_error_message();
			$this->assertNotSame( 'The gallery was not found.', $message );
		} finally {
			unload_textdomain( 'sw-gallery' );
			if ( $switched ) {
				restore_previous_locale();
			}
		}
	}

	public function test_unexpected_failures_are_generic_and_logged_safely(): void {
		$result = $this->mapper->map( new RuntimeException( "SELECT * FROM wp_secrets WHERE path='/var/www/secret.php'" ), new Actor( 7 ) );

		$this->assertSame( 'swg_internal_error', $result->get_error_code() );
		$this->assertSame( array( 'status' => 500 ), $result->get_error_data() );
		$this->assertStringNotContainsString( 'SELECT', $result->get_error_message() );
		$this->assertStringNotContainsString( '/var/www', $result->get_error_message() );
		$this->assertSame( array( 'swg_rest_unexpected_error' ), $this->logger->codes() );
		$this->assertSame( array( 'class' => RuntimeException::class ), $this->logger->entries[0]['context'] );
		$this->assertStringNotContainsString( 'secret', (string) wp_json_encode( $this->logger->entries[0]['context'] ) );
	}

	public function test_unmapped_domain_errors_are_treated_as_unexpected(): void {
		$result = $this->mapper->map( new ImportProviderException( 'swg_import_failed', true ), new Actor( 7 ) );

		$this->assertSame( 500, $result->get_error_data()['status'] );
		$this->assertSame( 'swg_internal_error', $result->get_error_code() );
		$this->assertCount( 1, $this->logger->entries );
	}

	public function test_request_error_helpers(): void {
		$this->assertSame( 400, ErrorMapper::bad_request( 'swg_x', 'm' )->get_error_data()['status'] );
		$this->assertSame( 422, ErrorMapper::unprocessable( 'swg_x', 'm', array( 'k' => 1 ) )->get_error_data()['status'] );
	}
}
