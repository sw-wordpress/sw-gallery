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

use SWGallery\Core\Application\Service\GalleryItemEditService;
use SWGallery\Core\Application\Service\GalleryItemService;
use SWGallery\Core\Application\Service\GalleryService;
use SWGallery\Core\Application\Service\LayoutRegistry;
use SWGallery\Core\Domain\Settings\SettingsSchemaValidator;
use SWGallery\Core\Event\EventDispatcher;
use SWGallery\WordPress\Media\WpMediaRepository;
use SWGallery\WordPress\Rendering\Layout\GridLayout;
use SWGallery\WordPress\Repository\PostMetaRevisionStore;
use SWGallery\WordPress\Repository\WpdbGalleryItemRepository;
use SWGallery\WordPress\Repository\WpdbGalleryRelations;
use SWGallery\WordPress\Repository\WpGalleryRepository;
use SWGallery\WordPress\Security\WpGalleryAccessPolicy;

final class Services {

	private static ?WpLogger $logger                  = null;
	private static ?WpTransactionRunner $transactions = null;
	private static ?EventDispatcher $events           = null;
	private static ?LayoutRegistry $layouts           = null;
	private static ?WpdbGalleryRelations $relations   = null;
	private static ?WpGalleryRepository $galleries    = null;
	private static ?WpdbGalleryItemRepository $items  = null;
	private static ?PostMetaRevisionStore $revisions  = null;
	private static ?WpMediaRepository $media          = null;
	private static ?WpGalleryAccessPolicy $access     = null;
	private static ?GalleryService $gallery_service   = null;
	private static ?GalleryItemService $item_service  = null;
	private static ?GalleryItemEditService $item_edit = null;

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

	/** Layout registry with the layouts this plugin ships (the public extension point arrives with M4-07). */
	public static function layouts(): LayoutRegistry {
		if ( null === self::$layouts ) {
			self::$layouts = new LayoutRegistry();
			self::$layouts->register( new GridLayout() );
		}
		return self::$layouts;
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

	/** Forgets all instances (tests). */
	public static function reset(): void {
		self::$logger          = null;
		self::$transactions    = null;
		self::$events          = null;
		self::$layouts         = null;
		self::$relations       = null;
		self::$galleries       = null;
		self::$items           = null;
		self::$revisions       = null;
		self::$media           = null;
		self::$access          = null;
		self::$gallery_service = null;
		self::$item_service    = null;
		self::$item_edit       = null;
	}
}
