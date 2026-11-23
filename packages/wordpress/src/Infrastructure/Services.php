<?php
/**
 * Holder of the shared services (one instance per request): infrastructure, adapters and the core application
 * services built from them. Only registrars and controllers factories read from here; everything else receives its
 * dependencies through constructors.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Infrastructure;

use SWGallery\Core\Application\Render\RenderGallery;
use SWGallery\Core\Application\Service\GalleryItemEditService;
use SWGallery\Core\Application\Service\GalleryItemService;
use SWGallery\Core\Application\Service\GalleryService;
use SWGallery\Core\Application\Service\ImportProviderRegistry;
use SWGallery\Core\Application\Service\LayoutRegistry;
use SWGallery\Core\Domain\Gallery\Gallery;
use SWGallery\Core\Domain\Settings\SettingsSchemaValidator;
use SWGallery\Core\Event\EventDispatcher;
use SWGallery\WordPress\Frontend\AssetCollector;
use SWGallery\WordPress\Frontend\EmbedArguments;
use SWGallery\WordPress\Frontend\EmbedRenderer;
use SWGallery\WordPress\Frontend\FrontendAssets;
use SWGallery\WordPress\Frontend\FrontendManifest;
use SWGallery\WordPress\Frontend\PreviewGalleries;
use SWGallery\WordPress\Gallery\GalleryEditor;
use SWGallery\WordPress\Gallery\GalleryLister;
use SWGallery\WordPress\Gallery\ItemEditor;
use SWGallery\WordPress\Gallery\ItemReader;
use SWGallery\WordPress\Media\MediaLister;
use SWGallery\WordPress\Rest\ItemPresenter;
use SWGallery\WordPress\Rest\MediaPresenter;
use SWGallery\WordPress\Rest\MediaSearch;
use SWGallery\WordPress\Hooks\ExtensionPoints;
use SWGallery\WordPress\Media\ThumbnailResolver;
use SWGallery\WordPress\Media\WpMediaRepository;
use SWGallery\WordPress\Rendering\GalleryRenderer;
use SWGallery\WordPress\Rendering\ItemSettingsRenderSource;
use SWGallery\WordPress\Rendering\MediaEpoch;
use SWGallery\WordPress\Rendering\RenderCache;
use SWGallery\WordPress\Rendering\Layout\BuiltInLayouts;
use SWGallery\WordPress\Rendering\View\FeedMarkup;
use SWGallery\WordPress\Repository\PostMetaRevisionStore;
use SWGallery\WordPress\Repository\WpdbGalleryItemRepository;
use SWGallery\WordPress\Repository\WpdbGalleryRelations;
use SWGallery\WordPress\Repository\WpGalleryRepository;
use SWGallery\WordPress\Rest\GalleryPresenter;
use SWGallery\WordPress\Security\WpGalleryAccessPolicy;
use SWGallery\WordPress\Settings\PluginSettings;

final class Services {

	private static ?WpLogger $logger                         = null;
	private static ?WpTransactionRunner $transactions        = null;
	private static ?EventDispatcher $events                  = null;
	private static ?LayoutRegistry $layouts                  = null;
	private static bool $layouts_announced                   = false;
	private static ?ImportProviderRegistry $import_providers = null;
	private static bool $providers_announced                 = false;
	private static ?WpdbGalleryRelations $relations          = null;
	private static ?WpGalleryRepository $galleries           = null;
	private static ?WpdbGalleryItemRepository $items         = null;
	private static ?PostMetaRevisionStore $revisions         = null;
	private static ?WpMediaRepository $media                 = null;
	private static ?WpGalleryAccessPolicy $access            = null;
	private static ?PluginSettings $settings                 = null;
	private static ?GalleryService $gallery_service          = null;
	private static ?GalleryEditor $gallery_editor            = null;
	private static ?GalleryLister $gallery_lister            = null;
	private static ?ItemReader $item_reader                  = null;
	private static ?ItemEditor $item_editor                  = null;
	private static ?MediaLister $media_lister                = null;
	private static ?GalleryItemService $item_service         = null;
	private static ?GalleryItemEditService $item_edit        = null;
	private static ?ThumbnailResolver $thumbnails            = null;
	private static ?RenderGallery $render_pipeline           = null;
	private static ?GalleryRenderer $gallery_renderer        = null;
	private static ?MediaEpoch $media_epoch                  = null;
	private static ?RenderCache $render_cache                = null;
	private static ?AssetCollector $asset_collector          = null;
	private static ?EmbedRenderer $embed_renderer            = null;
	private static ?EmbedArguments $embed_arguments          = null;
	private static ?FrontendAssets $frontend_assets          = null;

	public static function logger(): WpLogger {
		return self::$logger ??= new WpLogger();
	}

	public static function transactions(): WpTransactionRunner {
		return self::$transactions ??= new WpTransactionRunner( self::logger() );
	}

	/** The one core event dispatcher; the event bridge listens on it, use cases dispatch through it. */
	public static function events(): EventDispatcher {
		return self::$events ??= new EventDispatcher( self::logger() );
	}

	/**
	 * Layout registry: the built-ins first, then the public `swg_register_layouts` action (once per request, as soon as
	 * all plugins are loaded, so other plugins have attached their callbacks). The same instance is returned before and
	 * after the action ran.
	 */
	public static function layouts(): LayoutRegistry {
		if ( null === self::$layouts ) {
			self::$layouts = new LayoutRegistry();
			BuiltInLayouts::register( self::$layouts );
		}
		if ( ! self::$layouts_announced && did_action( 'plugins_loaded' ) > 0 ) {
			self::$layouts_announced = true; // Before the action: a callback that reads the registry must not recurse.
			ExtensionPoints::register_layouts( self::$layouts, self::logger() );
		}
		return self::$layouts;
	}

	/** Import provider registry, filled through the public `swg_register_import_providers` action (once per request). */
	public static function import_providers(): ImportProviderRegistry {
		self::$import_providers ??= new ImportProviderRegistry();
		if ( ! self::$providers_announced && did_action( 'plugins_loaded' ) > 0 ) {
			self::$providers_announced = true;
			ExtensionPoints::register_import_providers( self::$import_providers, self::logger() );
		}
		return self::$import_providers;
	}

	public static function relations(): WpdbGalleryRelations {
		return self::$relations ??= new WpdbGalleryRelations();
	}

	public static function galleries(): WpGalleryRepository {
		return self::$galleries ??= new WpGalleryRepository( self::relations() );
	}

	public static function items(): WpdbGalleryItemRepository {
		return self::$items ??= new WpdbGalleryItemRepository( self::transactions() );
	}

	public static function revisions(): PostMetaRevisionStore {
		return self::$revisions ??= new PostMetaRevisionStore();
	}

	public static function media(): WpMediaRepository {
		return self::$media ??= new WpMediaRepository();
	}

	public static function access(): WpGalleryAccessPolicy {
		return self::$access ??= new WpGalleryAccessPolicy();
	}

	public static function settings(): PluginSettings {
		return self::$settings ??= new PluginSettings( self::layouts() );
	}

	public static function status_report(): \SWGallery\WordPress\Rest\StatusReport {
		return new \SWGallery\WordPress\Rest\StatusReport( self::logger(), self::relations(), SWGALLERY_DIR . 'assets/admin' );
	}

	public static function gallery_service(): GalleryService {
		return self::$gallery_service ??= new GalleryService(
			self::galleries(),
			self::revisions(),
			self::transactions(),
			self::access(),
			self::events(),
			self::layouts(),
			new SettingsSchemaValidator()
		);
	}

	public static function gallery_editor(): GalleryEditor {
		return self::$gallery_editor ??= new GalleryEditor(
			self::gallery_service(),
			self::galleries(),
			self::revisions(),
			self::relations(),
			self::access(),
			new GalleryPresenter( self::access() ),
			self::settings()
		);
	}

	public static function gallery_lister(): GalleryLister {
		return self::$gallery_lister ??= new GalleryLister( self::galleries(), self::relations(), self::revisions(), new GalleryPresenter( self::access() ) );
	}

	public static function item_reader(): ItemReader {
		return self::$item_reader ??= new ItemReader( self::gallery_editor(), self::items(), new ItemPresenter( self::media(), self::access() ), self::revisions() );
	}

	public static function item_editor(): ItemEditor {
		return self::$item_editor ??= new ItemEditor( self::gallery_editor(), self::item_service(), self::item_edit_service(), self::items(), new ItemPresenter( self::media(), self::access() ) );
	}

	public static function media_lister(): MediaLister {
		return self::$media_lister ??= new MediaLister( new MediaSearch(), new MediaPresenter(), self::relations(), self::access() );
	}

	public static function item_service(): GalleryItemService {
		return self::$item_service ??= new GalleryItemService(
			self::items(),
			self::revisions(),
			self::media(),
			self::transactions(),
			self::events(),
			self::galleries(),
			self::access()
		);
	}

	public static function item_edit_service(): GalleryItemEditService {
		return self::$item_edit ??= new GalleryItemEditService(
			self::items(),
			self::galleries(),
			self::layouts(),
			self::revisions(),
			self::items(),
			self::items(),
			new SettingsSchemaValidator(),
			self::transactions(),
			self::events(),
			self::access()
		);
	}

	public static function thumbnails(): ThumbnailResolver {
		return self::$thumbnails ??= new ThumbnailResolver();
	}

	/** The framework independent render pipeline wired with the adapters. */
	public static function render_pipeline(): RenderGallery {
		return self::$render_pipeline ??= new RenderGallery(
			self::galleries(),
			self::access(),
			new ItemSettingsRenderSource( self::items() ),
			self::media(),
			self::thumbnails(),
			self::layouts(),
			new FeedMarkup(),
			new SettingsSchemaValidator(),
			self::logger(),
			self::revisions()
		);
	}

	/** The renderer every frontend surface (shortcode, block, PHP API, preview, feed) calls. */
	public static function gallery_renderer(): GalleryRenderer {
		return self::$gallery_renderer ??= new GalleryRenderer( self::render_pipeline(), self::galleries(), self::access(), self::settings(), self::render_cache() );
	}

	/** The token that invalidates cached fragments when media change (shared with the cache invalidation hooks). */
	public static function media_epoch(): MediaEpoch {
		return self::$media_epoch ??= new MediaEpoch();
	}

	/** Fragment cache of the public render output. */
	public static function render_cache(): RenderCache {
		return self::$render_cache ??= new RenderCache( self::settings(), self::revisions(), self::media_epoch() );
	}

	/**
	 * A renderer that sees one unsaved gallery state instead of the stored one (authorised preview, never persisted).
	 *
	 * @param Gallery $previewed Stored identity and status with the unsaved layout, sort mode and settings.
	 */
	public static function preview_renderer( Gallery $previewed ): GalleryRenderer {
		$galleries = new PreviewGalleries( self::galleries(), $previewed );
		$pipeline  = new RenderGallery(
			$galleries,
			self::access(),
			new ItemSettingsRenderSource( self::items() ),
			self::media(),
			self::thumbnails(),
			self::layouts(),
			new FeedMarkup(),
			new SettingsSchemaValidator(),
			self::logger(),
			self::revisions()
		);
		// Previews are never cached (the renderer skips the cache for EntryPoint::Preview).
		return new GalleryRenderer( $pipeline, $galleries, self::access(), self::settings(), self::render_cache() );
	}

	/** Request-scoped record of the galleries printed so far; every output surface reports to it. */
	public static function asset_collector(): AssetCollector {
		return self::$asset_collector ??= new AssetCollector();
	}

	/** Renderer plus collector bookkeeping, the call of the shortcode, the PHP API and the block. */
	public static function embed_renderer(): EmbedRenderer {
		return self::$embed_renderer ??= new EmbedRenderer( self::gallery_renderer(), self::asset_collector() );
	}

	public static function embed_arguments(): EmbedArguments {
		return self::$embed_arguments ??= new EmbedArguments( self::layouts() );
	}

	public static function frontend_assets(): FrontendAssets {
		return self::$frontend_assets ??= new FrontendAssets(
			new FrontendManifest( SWGALLERY_DIR . 'assets/build' ),
			self::asset_collector(),
			self::settings(),
			SWGALLERY_URL . 'assets/build'
		);
	}

	/** Forgets all instances (tests). */
	public static function reset(): void {
		self::$logger              = null;
		self::$transactions        = null;
		self::$events              = null;
		self::$layouts             = null;
		self::$layouts_announced   = false;
		self::$import_providers    = null;
		self::$providers_announced = false;
		self::$relations           = null;
		self::$galleries           = null;
		self::$items               = null;
		self::$revisions           = null;
		self::$media               = null;
		self::$access              = null;
		self::$settings            = null;
		self::$gallery_service     = null;
		self::$gallery_editor      = null;
		self::$gallery_lister      = null;
		self::$item_reader         = null;
		self::$item_editor         = null;
		self::$media_lister        = null;
		self::$item_service        = null;
		self::$item_edit           = null;
		self::$thumbnails          = null;
		self::$render_pipeline     = null;
		self::$gallery_renderer    = null;
		self::$media_epoch         = null;
		self::$render_cache        = null;
		self::$asset_collector     = null;
		self::$embed_renderer      = null;
		self::$embed_arguments     = null;
		self::$frontend_assets     = null;
	}
}
