<?php
/**
 * The focus layout (spotlight): stage panels as the only lightbox links, separate thumbnail links with `:target`
 * anchors unique per instance, settings, image hints and the reported script asset.
 *
 * @package sw-gallery
 */

use SWGallery\Core\Application\Render\RenderAsset;
use SWGallery\Core\Domain\Gallery\GallerySettings;
use SWGallery\Core\Domain\Gallery\SortMode;
use SWGallery\Core\Domain\Layout\LayoutRenderRequest;
use SWGallery\Core\Domain\Layout\LoadingStrategy;
use SWGallery\Core\Domain\Layout\RenderItem;
use SWGallery\Core\Domain\Layout\ResolvedImage;
use SWGallery\Core\Domain\Settings\SettingsSchemaValidator;
use SWGallery\WordPress\Rendering\Layout\FocusLayout;

class RenderFocusLayoutTest extends SwgTestCase {

	/**
	 * @param list<RenderItem>     $items    Items.
	 * @param array<string, mixed> $values   Layout settings.
	 * @param string               $instance Instance id.
	 */
	private function render( array $items, array $values = array(), string $instance = 'swg-12-1' ): string {
		return ( new FocusLayout() )->render( new LayoutRenderRequest( 12, $instance, $items, new GallerySettings( 'focus', SortMode::Manual, $values ) ) );
	}

	private function item( int $id = 1, string $caption = '', string $alt = 'A tree', LoadingStrategy $loading = LoadingStrategy::Lazy, int $width = 300, int $height = 200 ): RenderItem {
		return new RenderItem( $id, 100 + $id, 'Title ' . $id, $caption, $alt, new ResolvedImage( 'https://example.test/t' . $id . '.jpg', $width, $height, 'https://example.test/t.jpg 300w, https://example.test/t2.jpg 900w', '1200px' ), new ResolvedImage( 'https://example.test/f' . $id . '.jpg', 2000, 1333 ), $loading, $id, 4 );
	}

	/** @return list<RenderItem> */
	private function items( int $count = 4 ): array {
		$items = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$items[] = $this->item( $i, 1 === $i ? 'A <em>nice</em> tree' : '', 'Alt ' . $i, 1 === $i ? LoadingStrategy::Priority : ( 2 === $i ? LoadingStrategy::Eager : LoadingStrategy::Lazy ) );
		}
		return $items;
	}

	private function dom( string $html ): DOMXPath {
		$document = new DOMDocument();
		$document->loadHTML( '<?xml encoding="utf-8"?><body>' . $html . '</body>', LIBXML_NOERROR );
		return new DOMXPath( $document );
	}

	private function style( string $html ): string {
		return $this->dom( $html )->query( '//div[@data-swg-gallery]' )->item( 0 )->getAttribute( 'style' );
	}

	public function test_the_stage_panels_are_the_only_lightbox_links_in_order(): void {
		$xp = $this->dom( $this->render( $this->items() ) );

		$this->assertSame( 1, $xp->query( '//div[@class="swg-gallery swg-layout-focus"][@data-swg-gallery][@data-swg-instance="swg-12-1"]' )->length );
		$stage = $xp->query( '//div[@class="swg-stage"][@role="region"]' );
		$this->assertSame( 1, $stage->length );
		$this->assertSame( 'Selected image', $stage->item( 0 )->getAttribute( 'aria-label' ) );

		$links = $xp->query( '//div[@data-swg-gallery]//a[@class="swg-link"]' );
		$this->assertSame( 4, $links->length, 'Each image is exactly one lightbox link.' );
		$this->assertSame( 4, $xp->query( '//div[@class="swg-stage"]//ul[@class="swg-items"][@role="list"]/li[@class="swg-item swg-panel"]/a[@class="swg-link"]' )->length );
		$this->assertSame( 0, $xp->query( '//ul[@class="swg-thumbs"]//a[@class="swg-link"]' )->length, 'Thumbnails are not lightbox links.' );
		$hrefs = array();
		foreach ( $links as $link ) {
			$hrefs[] = $link->getAttribute( 'href' );
		}
		$this->assertSame( array( 'https://example.test/f1.jpg', 'https://example.test/f2.jpg', 'https://example.test/f3.jpg', 'https://example.test/f4.jpg' ), $hrefs, 'Stored order.' );
		$this->assertSame( 1, $xp->query( '//p[@class="swg-caption"]/em' )->length );
		$this->assertSame( 0, $xp->query( '//button' )->length );
	}

	public function test_thumbnails_are_in_page_links_to_unique_anchors(): void {
		$xp      = $this->dom( $this->render( $this->items() ) );
		$list    = $xp->query( '//ul[@class="swg-thumbs"][@role="list"]' );
		$anchors = $xp->query( '//span[@class="swg-anchor"]' );
		$thumbs  = $xp->query( '//ul[@class="swg-thumbs"]/li[@class="swg-thumb-item"]/a[@class="swg-thumb"]' );

		$this->assertSame( 1, $list->length );
		$this->assertSame( 'Thumbnails', $list->item( 0 )->getAttribute( 'aria-label' ) );
		$this->assertSame( 4, $anchors->length );
		$this->assertSame( 4, $thumbs->length );
		foreach ( $anchors as $i => $anchor ) {
			$this->assertSame( 'swg-12-1-s' . ( $i + 1 ), $anchor->getAttribute( 'id' ) );
			$this->assertSame( '#swg-12-1-s' . ( $i + 1 ), $thumbs->item( $i )->getAttribute( 'href' ) );
		}
		$this->assertSame( 4, $xp->query( '//li[@class="swg-item swg-panel"]/span[@class="swg-anchor"]/following-sibling::a[@class="swg-link"]' )->length );
	}

	public function test_instances_never_share_ids_and_ids_come_from_the_instance_id_only(): void {
		$one = $this->render( $this->items( 3 ), array(), 'swg-12-1' );
		$two = $this->render( $this->items( 3 ), array(), 'swg-12-2' );
		preg_match_all( '/ id="([^"]+)"/', $one . $two, $ids );
		$this->assertCount( 6, array_unique( $ids[1] ) );
		foreach ( $ids[1] as $id ) {
			$this->assertMatchesRegularExpression( '/^swg-12-[12]-s[123]$/', $id );
		}
	}

	public function test_thumbnail_names_and_loading(): void {
		$items = array( $this->item( 1, '', 'Alt 1', LoadingStrategy::Priority ), $this->item( 2, '', '', LoadingStrategy::Eager ), $this->item( 3, '', '', LoadingStrategy::Lazy ) );
		$xp    = $this->dom( $this->render( $items ) );

		$thumbs = $xp->query( '//a[@class="swg-thumb"]' );
		$this->assertFalse( $thumbs->item( 0 )->hasAttribute( 'aria-label' ), 'The alt text of the image names the link.' );
		$this->assertSame( 'Title 2', $thumbs->item( 1 )->getAttribute( 'aria-label' ), 'FU-25: title when there is no alt text.' );
		$stage = $xp->query( '//a[@class="swg-link"]' );
		$this->assertSame( 'Title 2', $stage->item( 1 )->getAttribute( 'aria-label' ) );

		$stage_imgs = $xp->query( '//a[@class="swg-link"]/img' );
		$this->assertSame( 'high', $stage_imgs->item( 0 )->getAttribute( 'fetchpriority' ) );
		$this->assertSame( 'eager', $stage_imgs->item( 0 )->getAttribute( 'loading' ) );
		$this->assertSame( 'lazy', $stage_imgs->item( 1 )->getAttribute( 'loading' ), 'Only the first stage image is eager, even if the pipeline said eager.' );
		$this->assertSame( 'lazy', $stage_imgs->item( 2 )->getAttribute( 'loading' ) );
		$this->assertSame( '1200px', $stage_imgs->item( 0 )->getAttribute( 'sizes' ) );

		$thumb_imgs = $xp->query( '//a[@class="swg-thumb"]/img' );
		$this->assertSame( 'eager', $thumb_imgs->item( 0 )->getAttribute( 'loading' ) );
		$this->assertFalse( $thumb_imgs->item( 0 )->hasAttribute( 'fetchpriority' ), 'Only one image is high priority.' );
		$this->assertSame( 'eager', $thumb_imgs->item( 1 )->getAttribute( 'loading' ) );
		$this->assertSame( 'lazy', $thumb_imgs->item( 2 )->getAttribute( 'loading' ) );
		$this->assertSame( '80px', $thumb_imgs->item( 0 )->getAttribute( 'sizes' ), 'Thumbnails use their own small sizes.' );
	}

	public function test_a_single_image_has_no_thumbnail_strip(): void {
		$xp = $this->dom( $this->render( array( $this->item() ) ) );
		$this->assertSame( 1, $xp->query( '//a[@class="swg-link"]' )->length );
		$this->assertSame( 0, $xp->query( '//ul[@class="swg-thumbs"]' )->length );
	}

	public function test_labels_carry_the_stage_and_thumbnail_names(): void {
		$labels = json_decode( $this->dom( $this->render( $this->items() ) )->query( '//div[@data-swg-gallery]' )->item( 0 )->getAttribute( 'data-swg-labels' ), true );
		$this->assertSame( 'Selected image', $labels['stage'] );
		$this->assertSame( 'Thumbnails', $labels['thumbnails'] );
		$this->assertArrayHasKey( 'counter', $labels );
	}

	public function test_settings_become_validated_custom_properties_and_classes(): void {
		$style = fn( array $values ): string => $this->style( $this->render( $this->items(), $values ) );
		$this->assertSame( '--swg-stage-ratio:3/2;--swg-thumb-size:80px;--swg-gap:8px;--swg-thumb-radius:4px', $style( array() ) );
		$this->assertSame(
			'--swg-stage-ratio:16/9;--swg-thumb-size:120px;--swg-gap:0px;--swg-thumb-radius:12px',
			$style(
				array(
					'stageRatio' => '16:9',
					'thumbSize'  => 120,
					'gap'        => 0,
					'radius'     => 12,
				)
			)
		);
		foreach ( array(
			'4:3' => '4/3',
			'1:1' => '1/1',
			'3:2' => '3/2',
		) as $setting => $css ) {
			$this->assertStringStartsWith( '--swg-stage-ratio:' . $css . ';', $style( array( 'stageRatio' => $setting ) ) );
		}
		$this->assertSame( '--swg-stage-ratio:3/2;--swg-thumb-size:160px;--swg-gap:64px;--swg-thumb-radius:64px', $style(
			array(
				'thumbSize' => 9999,
				'gap'       => 9999,
				'radius'    => 9999,
			)
		) );
		$this->assertSame( '--swg-stage-ratio:3/2;--swg-thumb-size:40px;--swg-gap:0px;--swg-thumb-radius:0px', $style(
			array(
				'thumbSize' => 1,
				'gap'       => -1,
				'radius'    => -1,
			)
		) );
		$this->assertSame(
			'--swg-stage-ratio:3/2;--swg-thumb-size:80px;--swg-gap:8px;--swg-thumb-radius:4px',
			$style(
				array(
					'stageRatio' => '1;background:red',
					'thumbSize'  => '1;x',
					'gap'        => 'x',
					'radius'     => 'red;} body{display:none',
				)
			),
			'Hostile values never reach the style attribute.'
		);
		$class = fn( array $values ): string => $this->dom( $this->render( $this->items(), $values ) )->query( '//div[@data-swg-gallery]' )->item( 0 )->getAttribute( 'class' );
		$this->assertSame( 'swg-gallery swg-layout-focus swg-thumbs-start', $class( array( 'thumbs' => 'start' ) ) );
		$this->assertSame( 'swg-gallery swg-layout-focus', $class( array( 'thumbs' => 'bottom' ) ) );
		$this->assertSame( 'swg-gallery swg-layout-focus', $class( array( 'thumbs' => 'x" onclick="y' ) ) );
	}

	public function test_the_auto_ratio_follows_the_first_image_within_limits(): void {
		$ratio = fn( int $w, int $h ): string => $this->style( $this->render( array( $this->item( 1, '', 'a', LoadingStrategy::Lazy, $w, $h ), $this->item( 2 ) ), array( 'stageRatio' => 'auto' ) ) );
		$this->assertStringStartsWith( '--swg-stage-ratio:1.3333;', $ratio( 400, 300 ) );
		$this->assertStringStartsWith( '--swg-stage-ratio:0.5;', $ratio( 100, 1000 ) );
		$this->assertStringStartsWith( '--swg-stage-ratio:2.5;', $ratio( 4000, 100 ) );
		$this->assertStringStartsWith( '--swg-stage-ratio:1.5;', $ratio( 0, 0 ) );
	}

	public function test_hostile_text_and_urls_are_escaped(): void {
		$item = new RenderItem( 1, 5, '"><script>alert(1)</script>', '<img src=x onerror=alert(1)><script>alert(2)</script>', '"><script>alert(4)</script>', new ResolvedImage( 'https://example.test/t.jpg?a=1&b="2"', 10, 10 ), new ResolvedImage( 'javascript:alert(1)', 10, 10 ) );
		$html = $this->render( array( $item, $item ), array( 'thumbSize' => '1" onclick="x' ), 'swg-12-1' );

		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( 'onerror', $html );
		$this->assertStringNotContainsString( 'onclick', $html );
		$this->assertStringNotContainsString( 'javascript:', $html );
	}

	public function test_an_empty_page_renders_nothing_and_the_layout_reports_the_script(): void {
		$layout = new FocusLayout();
		$this->assertSame( '', $this->render( array() ) );
		$this->assertSame( array( RenderAsset::Focus ), $layout->renderAssets() );
		$this->assertSame( 'focus', $layout->getId() );
		$this->assertSame( 'Focus', $layout->getLabel() );
	}

	public function test_the_schema_validation_matrix(): void {
		$schema    = ( new FocusLayout() )->getSettingsSchema();
		$validator = new SettingsSchemaValidator();

		$this->assertSame( array( 'auto', '16:9', '3:2', '4:3', '1:1' ), $schema['properties']['stageRatio']['enum'] );
		$this->assertSame( '3:2', $schema['properties']['stageRatio']['default'] );
		$this->assertSame( array( 'bottom', 'start' ), array_keys( $schema['properties']['thumbs']['x-enum-titles'] ) );
		foreach ( array(
			array(
				'stageRatio' => 'auto',
				'thumbs'     => 'start',
				'thumbSize'  => 40,
				'gap'        => 0,
				'radius'     => 0,
			),
			array(
				'stageRatio' => '1:1',
				'thumbs'     => 'bottom',
				'thumbSize'  => 160,
				'gap'        => 64,
				'radius'     => 64,
			),
			array( 'unknown_future' => true ),
		) as $valid ) {
			$this->assertTrue( $validator->validate( $schema, $valid )->isValid(), (string) wp_json_encode( $valid ) );
		}
		foreach ( array(
			array( 'stageRatio' => '21:9' ),
			array( 'stageRatio' => 1 ),
			array( 'thumbs' => 'end' ),
			array( 'thumbSize' => 39 ),
			array( 'thumbSize' => 161 ),
			array( 'gap' => 65 ),
			array( 'radius' => -1 ),
			array( 'thumbSize' => 'big' ),
		) as $invalid ) {
			$this->assertFalse( $validator->validate( $schema, $invalid )->isValid(), (string) wp_json_encode( $invalid ) );
		}
	}

	public function test_image_hints_request_a_large_stage_image(): void {
		$layout = new FocusLayout();
		$def    = $layout->imageHints( new GallerySettings( 'focus', SortMode::Manual, array() ) );
		$big    = $layout->imageHints( new GallerySettings( 'focus', SortMode::Manual, array( 'thumbSize' => 160 ) ) );

		$this->assertSame( 1200, $def->displayWidth );
		$this->assertSame( 'min(100vw, 1200px)', $def->sizes );
		$this->assertSame( 6, $def->firstRowCount, 'Capped at the maximum.' );
		$this->assertSame( 6, $big->firstRowCount );
		$many = $layout->imageHints( new GallerySettings( 'focus', SortMode::Manual, array( 'thumbSize' => 160, 'gap' => 64 ) ) );
		$this->assertSame( 5, $many->firstRowCount, 'floor(1200 / 224).' );
	}
}
