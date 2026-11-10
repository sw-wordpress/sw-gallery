<?php

declare(strict_types=1);

namespace SWGallery\Tests\Support;

use SWGallery\WordPress\Database\Schema;
use SWGallery\WordPress\PostTypes;

/**
 * Fixture helpers of the WordPress integration suite (users, image attachments, items table), usable from
 * WP_UnitTestCase based tests (rolled back) and from plain test cases (cleaned with purgeGalleries()).
 */
trait WpFixtures
{
    /** @var list<int> */
    private array $fixtureAttachments = [];

    protected function makeUser(string $role): int
    {
        $id = wp_insert_user(
            [
                'user_login' => 'swg_' . strtolower(wp_generate_password(10, false)),
                'user_pass' => wp_generate_password(),
                'role' => $role,
            ]
        );
        self::assertIsInt($id);

        return $id;
    }

    /**
     * @param array<string, mixed> $extra Extra post fields (post_status, post_parent, post_excerpt, ...).
     */
    protected function makeAttachment(int $owner = 0, string $mime = 'image/jpeg', array $extra = []): int
    {
        $id = wp_insert_attachment(
            $extra + [
                'post_mime_type' => $mime,
                'post_title' => 'Image ' . wp_generate_password(6, false),
                'post_status' => 'inherit',
                'post_author' => $owner,
            ]
        );
        self::assertIsInt($id);
        self::assertGreaterThan(0, $id);
        $this->fixtureAttachments[] = $id;

        return $id;
    }

    /** Re-registers the gallery post type and meta when a previous test case unregistered them. */
    protected function ensureGalleryTypeRegistered(): void
    {
        if (!registered_meta_key_exists('post', PostTypes::META_REV, PostTypes::GALLERY)) {
            unregister_post_type(PostTypes::GALLERY);
            PostTypes::register();
        }
    }

    /**
     * Removes every gallery post, every item relation and the attachments made by makeAttachment(). Needed because
     * SwgTestCase::tear_down() truncates the items table (DDL commits implicitly), so data of WP_UnitTestCase tests is
     * not rolled back either.
     */
    protected function purgeGalleries(): void
    {
        global $wpdb;
        foreach ($this->fixtureAttachments as $attachmentId) {
            wp_delete_attachment($attachmentId, true);
        }
        $this->fixtureAttachments = [];
        $ids = get_posts(
            [
                'post_type' => PostTypes::GALLERY,
                'post_status' => 'any',
                'posts_per_page' => -1,
                'fields' => 'ids',
                'suppress_filters' => true,
            ]
        );
        foreach ($ids as $id) {
            wp_delete_post((int) $id, true);
        }
        $table = Schema::items_table();
        $wpdb->query("DELETE FROM `{$table}`");
    }

    protected function countItemRows(): int
    {
        global $wpdb;
        $table = Schema::items_table();

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
    }
}
