<?php
/**
 * Bulk fixtures for performance work: cheap but realistic image attachments and gallery items in large numbers.
 * Shared by `bin/seed.php` (WP-CLI) and the PHPUnit performance tests. Never shipped (tests/ is outside the build).
 *
 * Trade-off (documented in docs/performance.md): attachments are inserted with batched multi-row INSERTs into the posts
 * and postmeta tables instead of `wp_insert_attachment()`/`wp_generate_attachment_metadata()`. That is ~100x faster
 * (10k attachments in about 1 s instead of minutes) and produces exactly the rows WordPress writes for an image upload
 * (`_wp_attached_file`, `_wp_attachment_metadata` with the registered sub-sizes, `_wp_attachment_image_alt`). All
 * attachments of one template share the same few tiny real files, so a seeded site renders real pictures
 * (`$with_files`); without files only the database rows exist (tests). Hooks of the attachment lifecycle do not run.
 *
 * @package sw-gallery
 */

// phpcs:disable

final class SwgSeeder {

	/** Slug prefix that marks seeded attachments (and the title prefix of seeded galleries), used by purge(). */
	public const SLUG_PREFIX  = 'swg-seed-';
	public const TITLE_PREFIX = 'Seed gallery ';
	public const UPLOAD_DIR   = 'swg-seed';

	private const CHUNK = 500;

	/** @var list<array{0:int,1:int}> Template pixel sizes (full size): several aspect ratios on purpose. */
	private const TEMPLATES = array(
		array( 1200, 800 ),
		array( 1200, 900 ),
		array( 1000, 1000 ),
		array( 1280, 720 ),
		array( 800, 1200 ),
		array( 900, 1200 ),
	);

	/** Registered default sizes: name => [max width, max height, crop]. */
	private const SIZES = array(
		'thumbnail'    => array( 150, 150, true ),
		'medium'       => array( 300, 300, false ),
		'medium_large' => array( 768, 0, false ),
		'large'        => array( 1024, 1024, false ),
	);

	/**
	 * The CLI guard: seeding is refused on anything that is not a local/development/staging site unless forced.
	 *
	 * @throws \RuntimeException When the environment is not allowed.
	 */
	public static function assert_allowed( bool $force ): void {
		$env = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
		if ( ! $force && ! in_array( $env, array( 'local', 'development', 'staging' ), true ) ) {
			throw new \RuntimeException( "Refusing to seed: WP_ENVIRONMENT_TYPE is '{$env}' (allowed: local, development, staging). Pass --force to override." );
		}
	}

	/**
	 * Creates image attachments in bulk.
	 *
	 * @param int  $count      Number of attachments.
	 * @param bool $with_files Also write the few tiny template files into uploads/swg-seed (needs GD).
	 * @return list<int> Attachment ids in creation order.
	 */
	public static function attachments( int $count, bool $with_files = false ): array {
		global $wpdb;
		if ( $count < 1 ) {
			return array();
		}
		if ( $with_files ) {
			self::write_template_files();
		}
		$ids   = array();
		$now   = gmdate( 'Y-m-d H:i:s' );
		$batch = self::random_token();
		for ( $offset = 0; $offset < $count; $offset += self::CHUNK ) {
			$rows = array();
			$size = min( self::CHUNK, $count - $offset );
			for ( $i = 0; $i < $size; $i++ ) {
				$n      = $offset + $i + 1;
				$slug   = self::SLUG_PREFIX . $batch . '-' . $n;
				$rows[] = $wpdb->prepare(
					'(0,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%d,%s,%d,%s,%s,%d)',
					$now,
					$now,
					'',
					'Seed image ' . $n,
					'Caption ' . $n,
					'inherit',
					'open',
					'closed',
					'',
					$slug,
					'',
					'',
					$now,
					$now,
					'',
					0,
					$slug,
					0,
					'attachment',
					'image/jpeg',
					0
				);
			}
			$wpdb->query( 'INSERT INTO ' . $wpdb->posts . ' (post_author,post_date,post_date_gmt,post_content,post_title,post_excerpt,post_status,comment_status,ping_status,post_password,post_name,to_ping,pinged,post_modified,post_modified_gmt,post_content_filtered,post_parent,guid,menu_order,post_type,post_mime_type,comment_count) VALUES ' . implode( ',', $rows ) );
			$first = (int) $wpdb->insert_id;
			// Consecutive auto-increment ids (innodb_autoinc_lock_mode 0/1 guarantee this for a single multi-row INSERT).
			$chunk_ids = range( $first, $first + $size - 1 );
			self::attachment_meta( $chunk_ids, $offset );
			array_push( $ids, ...$chunk_ids );
		}
		return $ids;
	}

	/**
	 * @param list<int> $ids    Attachment ids of one chunk.
	 * @param int       $offset Number of attachments created before this chunk (selects the template).
	 */
	private static function attachment_meta( array $ids, int $offset ): void {
		global $wpdb;
		$rows = array();
		foreach ( $ids as $i => $id ) {
			$template = ( $offset + $i ) % count( self::TEMPLATES );
			$meta     = self::metadata( $template );
			$rows[]   = $wpdb->prepare( '(%d,%s,%s)', $id, '_wp_attached_file', $meta['file'] );
			$rows[]   = $wpdb->prepare( '(%d,%s,%s)', $id, '_wp_attachment_metadata', serialize( $meta ) );
			$rows[]   = $wpdb->prepare( '(%d,%s,%s)', $id, '_wp_attachment_image_alt', 'Seed image ' . ( $offset + $i + 1 ) );
		}
		$wpdb->query( 'INSERT INTO ' . $wpdb->postmeta . ' (post_id,meta_key,meta_value) VALUES ' . implode( ',', $rows ) );
	}

	/**
	 * The attachment metadata WordPress would store for a template, sub-sizes keep the original's aspect ratio.
	 *
	 * @return array<string, mixed>
	 */
	public static function metadata( int $template ): array {
		[ $w, $h ] = self::TEMPLATES[ $template ];
		$base      = self::UPLOAD_DIR . '/swg-seed-' . $template;
		$sizes     = array();
		foreach ( self::SIZES as $name => [ $max_w, $max_h, $crop ] ) {
			if ( $crop ) {
				$sw = min( $max_w, $w );
				$sh = min( $max_h, $h );
			} else {
				$scale = min( $max_w / $w, $max_h > 0 ? $max_h / $h : PHP_INT_MAX, 1 );
				if ( $scale >= 1 ) {
					continue;
				}
				$sw = (int) round( $w * $scale );
				$sh = (int) round( $h * $scale );
			}
			$sizes[ $name ] = array(
				'file'      => "swg-seed-{$template}-{$sw}x{$sh}.jpg",
				'width'     => $sw,
				'height'    => $sh,
				'mime-type' => 'image/jpeg',
			);
		}
		return array(
			'width'      => $w,
			'height'     => $h,
			'file'       => $base . '.jpg',
			'filesize'   => 4096,
			'sizes'      => $sizes,
			'image_meta' => array(),
		);
	}

	/** Writes the template pictures (flat colour gradients, a few KB each) once; existing files are kept. */
	private static function write_template_files(): void {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			throw new \RuntimeException( 'The GD extension is required for --with-files.' );
		}
		$uploads = wp_upload_dir( null, true );
		$dir     = $uploads['basedir'] . '/' . self::UPLOAD_DIR;
		if ( ! wp_mkdir_p( $dir ) || ! is_writable( $dir ) ) {
			throw new \RuntimeException( 'The uploads directory is not writable.' );
		}
		foreach ( array_keys( self::TEMPLATES ) as $template ) {
			$meta  = self::metadata( $template );
			$files = array( $dir . '/swg-seed-' . $template . '.jpg' => array( $meta['width'], $meta['height'] ) );
			foreach ( $meta['sizes'] as $size ) {
				$files[ $dir . '/' . $size['file'] ] = array( $size['width'], $size['height'] );
			}
			foreach ( $files as $path => [ $w, $h ] ) {
				if ( file_exists( $path ) ) {
					continue;
				}
				$image = imagecreatetruecolor( $w, $h );
				for ( $y = 0; $y < $h; $y += 4 ) {
					$t     = (int) ( 255 * $y / max( 1, $h ) );
					$color = imagecolorallocate( $image, ( 40 + $template * 35 ) % 256, 90 + (int) ( $t / 3 ), 200 - (int) ( $t / 2 ) );
					imagefilledrectangle( $image, 0, $y, $w, $y + 3, $color );
				}
				imagejpeg( $image, $path, 60 );
				imagedestroy( $image );
			}
		}
	}

	/**
	 * Creates a published gallery post and, optionally, its items in one batched INSERT per chunk.
	 *
	 * @param list<int> $attachment_ids Attachments in display order.
	 * @return int Gallery id.
	 */
	public static function gallery( string $title, array $attachment_ids, int $owner = 0, string $status = 'publish' ): int {
		$id = wp_insert_post(
			array(
				'post_type'   => \SWGallery\WordPress\PostTypes::GALLERY,
				'post_status' => $status,
				'post_title'  => $title,
				'post_author' => $owner,
			)
		);
		if ( ! is_int( $id ) || $id < 1 ) {
			throw new \RuntimeException( 'Gallery could not be created.' );
		}
		self::items( $id, $attachment_ids );
		return $id;
	}

	/**
	 * Appends relations with positions 1..n directly (the repository's append() costs two queries per item). Rows
	 * match what WpdbGalleryItemRepository::append() writes.
	 *
	 * @param list<int> $attachment_ids Attachments in display order.
	 */
	public static function items( int $gallery_id, array $attachment_ids ): void {
		global $wpdb;
		$table = \SWGallery\WordPress\Database\Schema::items_table();
		$now   = gmdate( 'Y-m-d H:i:s' );
		foreach ( array_chunk( $attachment_ids, 1000, true ) as $chunk ) {
			$rows = array();
			foreach ( $chunk as $index => $attachment_id ) {
				$rows[] = $wpdb->prepare( '(%d,%d,%d,%s,%s)', $gallery_id, $attachment_id, $index + 1, $now, $now );
			}
			if ( false === $wpdb->query( "INSERT INTO `{$table}` (gallery_id,attachment_id,position,created_at,updated_at) VALUES " . implode( ',', $rows ) ) ) {
				throw new \RuntimeException( 'Items could not be inserted.' );
			}
		}
	}

	/**
	 * `wp eval-file bin/seed.php -- --items=N --galleries=M`: M galleries with N items each (all galleries share one
	 * pool of N seeded attachments).
	 *
	 * @param array<string, mixed> $args items, galleries, force, with-files.
	 * @return array<string, int|float>
	 */
	public static function run( array $args ): array {
		self::assert_allowed( ! empty( $args['force'] ) );
		$items     = max( 1, (int) ( $args['items'] ?? 100 ) );
		$galleries = max( 1, (int) ( $args['galleries'] ?? 1 ) );
		$started   = microtime( true );
		$pool      = self::attachments( $items, array_key_exists( 'with-files', $args ) ? (bool) $args['with-files'] : true );
		$gallery_ids = array();
		for ( $g = 1; $g <= $galleries; $g++ ) {
			$gallery_ids[] = self::gallery( self::TITLE_PREFIX . $g . ' (' . $items . ' items)', $pool );
		}
		return array(
			'attachments' => count( $pool ),
			'galleries'   => count( $gallery_ids ),
			'items'       => count( $pool ) * count( $gallery_ids ),
			'first_gallery' => $gallery_ids[0],
			'seconds'     => round( microtime( true ) - $started, 2 ),
		);
	}

	/**
	 * Removes everything this seeder created (marked by slug/title prefix), with direct SQL. Never touches other
	 * posts; the shared template files stay.
	 *
	 * @return array<string, int>
	 */
	public static function purge(): array {
		global $wpdb;
		$table = \SWGallery\WordPress\Database\Schema::items_table();
		$like  = $wpdb->esc_like( self::TITLE_PREFIX ) . '%';
		$gal   = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_title LIKE %s", \SWGallery\WordPress\PostTypes::GALLERY, $like ) ) );
		$att   = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_name LIKE %s", $wpdb->esc_like( self::SLUG_PREFIX ) . '%' ) ) );
		foreach ( array_chunk( $gal, 100 ) as $chunk ) {
			foreach ( $chunk as $id ) {
				wp_delete_post( $id, true );
			}
		}
		$wpdb->query( "DELETE FROM `{$table}` WHERE attachment_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_name LIKE '" . esc_sql( $wpdb->esc_like( self::SLUG_PREFIX ) ) . "%')" );
		foreach ( array_chunk( $att, 1000 ) as $chunk ) {
			$in = implode( ',', $chunk );
			$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$in})" );
			$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE ID IN ({$in})" );
		}
		return array(
			'galleries'   => count( $gal ),
			'attachments' => count( $att ),
		);
	}

	private static function random_token(): string {
		return substr( bin2hex( random_bytes( 4 ) ), 0, 6 );
	}
}
