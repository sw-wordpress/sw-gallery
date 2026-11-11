<?php

declare(strict_types=1);

namespace SWGallery\Tests\Support;

use SWGallery\Core\Domain\Access\Actor;
use SWGallery\Core\Domain\Gallery\GalleryStatus;
use SWGallery\Core\Domain\Gallery\NewGallery;
use SWGallery\WordPress\Infrastructure\EventBridgeRegistrar;
use SWGallery\WordPress\Infrastructure\Services;

/**
 * Helpers of the REST integration tests: a fresh REST server wired to fresh services and requests dispatched as a
 * given user (`rest_do_request`, so permission callbacks, argument validation and sanitising really run).
 */
trait RestFixtures
{
    use WpFixtures;

    protected function bootRest(): void
    {
        global $wp_rest_server;
        $this->purgeAllGalleries();
        Services::reset();
        (new EventBridgeRegistrar())->register();
        $this->ensureGalleryTypeRegistered();
        $wp_rest_server = null;
        rest_get_server();
    }

    protected function shutdownRest(): void
    {
        global $wp_rest_server;
        wp_set_current_user(0);
        $wp_rest_server = null;
        $this->purgeAllGalleries();
        Services::reset();
    }

    /** Like purgeGalleries(), but also removes trashed galleries (post_status `any` skips the trash). */
    protected function purgeAllGalleries(): void
    {
        foreach (get_posts(['post_type' => 'swg_gallery', 'post_status' => 'trash', 'posts_per_page' => -1, 'fields' => 'ids', 'suppress_filters' => true]) as $id) {
            wp_delete_post((int) $id, true);
        }
        $this->purgeGalleries();
    }

    /**
     * @param array<string, mixed> $params JSON body for write methods, query string for GET.
     * @param array<string, mixed> $query  Extra query parameters (for example `expectedRevision` of DELETE).
     */
    protected function call(string $method, string $route, array $params = [], int $user = 0, array $query = []): \WP_REST_Response
    {
        wp_set_current_user($user);
        $request = new \WP_REST_Request($method, '/swg/v1' . $route);
        if ($method === 'GET') {
            $request->set_query_params($params + $query);
        } else {
            $request->set_query_params($query);
            if ($params !== []) {
                $request->set_header('content-type', 'application/json');
                $request->set_body((string) wp_json_encode($params));
            }
        }

        return rest_do_request($request);
    }

    /** Creates a gallery through the service as the given user. */
    protected function makeGallery(int $owner, GalleryStatus $status = GalleryStatus::Draft, string $title = 'Gallery'): int
    {
        $result = Services::gallery_service()->create(new Actor($owner), new NewGallery($title, $owner, '', $status));

        return $result->gallery->id;
    }

    /**
     * @return array<string, mixed>
     */
    protected function data(\WP_REST_Response $response): array
    {
        $data = $response->get_data();
        self::assertIsArray($data);

        return $data;
    }
}
