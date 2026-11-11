<?php

declare(strict_types=1);

namespace SWGallery\Tests\Support;

use SWGallery\WordPress\Infrastructure\Services;
use SWGallery\WordPress\PostTypes;

/**
 * Fixtures of the render tests: image attachments with realistic metadata (no files on disk), galleries as posts in any
 * status and items through the real repository.
 */
trait RenderFixtures
{
    use WpFixtures;

    /**
     * @param array<string, mixed>                                             $extra Extra post fields.
     * @param array<string, array{file: string, width: int, height: int}>|null $sizes Sub-sizes; null derives the core defaults.
     */
    protected function makeImage(
        string $title = 'Photo',
        int $width = 2000,
        int $height = 1500,
        array $extra = [],
        ?array $sizes = null,
        string $alt = '',
        string $mime = 'image/jpeg'
    ): int {
        $id = $this->makeAttachment(0, $mime, ['post_title' => $title] + $extra);
        update_post_meta($id, '_wp_attached_file', "2026/11/photo-$id.jpg");
        if ($alt !== '') {
            update_post_meta($id, '_wp_attachment_image_alt', wp_slash($alt));
        }
        $meta = ['width' => $width, 'height' => $height, 'file' => "2026/11/photo-$id.jpg", 'sizes' => $sizes ?? $this->defaultSizes($id, $width, $height)];
        wp_update_attachment_metadata($id, $meta);

        return $id;
    }

    /**
     * @return array<string, array{file: string, width: int, height: int, 'mime-type': string}>
     */
    protected function defaultSizes(int $id, int $width, int $height): array
    {
        $sizes = [];
        foreach (['thumbnail' => [150, 150], 'medium' => [300, 300], 'medium_large' => [768, 0], 'large' => [1024, 1024]] as $name => [$maxW, $maxH]) {
            if ($name === 'thumbnail') {
                [$w, $h] = [min(150, $width), min(150, $height)];
            } else {
                $scale = min($maxW / $width, $maxH > 0 ? $maxH / $height : PHP_INT_MAX, 1);
                [$w, $h] = [(int) round($width * $scale), (int) round($height * $scale)];
                if ($scale >= 1) {
                    continue;
                }
            }
            $sizes[$name] = ['file' => "photo-$id-{$w}x{$h}.jpg", 'width' => $w, 'height' => $h, 'mime-type' => 'image/jpeg'];
        }

        return $sizes;
    }

    /**
     * @param array<string, mixed> $settings Layout settings stored in `_swg_settings`.
     */
    protected function makeGalleryPost(
        int $owner,
        string $status = 'publish',
        string $password = '',
        string $layout = 'grid',
        string $sort = 'manual',
        array $settings = []
    ): int {
        $this->ensureGalleryTypeRegistered();
        $id = wp_insert_post(
            [
                'post_type' => PostTypes::GALLERY,
                'post_status' => $status === 'trash' ? 'publish' : $status,
                'post_title' => 'Render gallery',
                'post_author' => $owner,
                'post_password' => $password,
            ]
        );
        self::assertIsInt($id);
        self::assertGreaterThan(0, $id);
        update_post_meta($id, PostTypes::META_LAYOUT, $layout);
        update_post_meta($id, PostTypes::META_SORT_MODE, $sort);
        if ($settings !== []) {
            update_post_meta($id, PostTypes::META_SETTINGS, wp_slash((string) wp_json_encode($settings)));
        }
        if ($status === 'trash') {
            wp_trash_post($id);
        }

        return $id;
    }

    /**
     * @param list<int> $attachments
     * @return list<int> Item ids in order.
     */
    protected function addItems(int $galleryId, array $attachments): array
    {
        $ids = [];
        foreach ($attachments as $attachment) {
            $ids[] = Services::items()->append($galleryId, $attachment)->id;
        }

        return $ids;
    }

    /** Sets the cookie WordPress reads to decide whether a protected post was unlocked. */
    protected function unlockPassword(string $password): void
    {
        // The same phpass cookie value wp-login.php writes (post_password_required() only accepts `$P$B` hashes).
        require_once ABSPATH . WPINC . '/class-phpass.php';
        $_COOKIE['wp-postpass_' . COOKIEHASH] = (new \PasswordHash(8, true))->HashPassword($password);
    }

    protected function lockPassword(): void
    {
        unset($_COOKIE['wp-postpass_' . COOKIEHASH]);
    }

    protected function itemRowCount(int $galleryId): int
    {
        return Services::items()->count($galleryId);
    }
}
