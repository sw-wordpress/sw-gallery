<?php

declare(strict_types=1);

namespace SWGallery\Tests\Support;

/**
 * Upload helpers of the media REST tests: real image files in the temp directory and simulated multipart uploads
 * (`WP_REST_Request::set_file_params`, like the core REST tests).
 */
trait ImageFixtures
{
    /** @var list<string> */
    private array $imageTempFiles = [];

    /**
     * Writes a small real image (GD) to a temporary file.
     *
     * @param 'jpeg'|'png'|'gif'|'webp' $format
     */
    protected function makeImageFile(string $format = 'jpeg', int $width = 64, int $height = 48): string
    {
        $image = imagecreatetruecolor($width, $height);
        self::assertNotFalse($image);
        imagefilledrectangle($image, 0, 0, $width, $height, (int) imagecolorallocate($image, 200, 60, 40));
        $path = $this->tempFile($format);
        match ($format) {
            'png' => imagepng($image, $path),
            'gif' => imagegif($image, $path),
            'webp' => imagewebp($image, $path),
            default => imagejpeg($image, $path),
        };
        imagedestroy($image);

        return $path;
    }

    protected function tempFile(string $suffix = 'tmp', string $content = ''): string
    {
        $path = (string) wp_tempnam('swg-upload-' . $suffix);
        file_put_contents($path, $content);
        $this->imageTempFiles[] = $path;

        return $path;
    }

    /**
     * @param array<string, mixed> $file Overrides of the `$_FILES` entry (name, type, error, size, tmp_name).
     * @param array<string, mixed> $body Extra form fields (title, alt).
     */
    protected function upload(
        string $path,
        string $name,
        int $user,
        array $file = [],
        array $body = []
    ): \WP_REST_Response {
        wp_set_current_user($user);
        $request = new \WP_REST_Request('POST', '/swg/v1/media');
        $request->set_header('content-type', 'multipart/form-data; boundary=x');
        $request->set_file_params(['file' => $file + [
            'name' => $name,
            'type' => 'application/octet-stream',
            'tmp_name' => $path,
            'error' => UPLOAD_ERR_OK,
            'size' => (int) @filesize($path),
        ]]);
        $request->set_body_params($body);

        return rest_do_request($request);
    }

    protected function removeImageTempFiles(): void
    {
        foreach ($this->imageTempFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->imageTempFiles = [];
    }
}
