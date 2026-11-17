<?php
/** CLI artifact validation using the same manifest/package verifiers as WordPress; never executes archive PHP. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }
// The pure verifiers only need WordPress' error and translation boundary, not a running WordPress install.
final class WP_Error {
    public function __construct(public string $code, public string $message, public mixed $data = null) {}
    public function get_error_message(): string { return $this->message; }
}
function __(string $text, string $domain): string { return $text; }
require dirname(__DIR__) . '/packages/wordpress/src/Update/Manifest.php';
require dirname(__DIR__) . '/packages/wordpress/src/Update/Package.php';
try {
    if (count($argv) !== 4) { throw new RuntimeException('Usage: verify-signed-release.php release.json zip-directory trusted-keys.php'); }
    $release = json_decode((string) file_get_contents($argv[1]), true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($release)) { throw new RuntimeException('Invalid release metadata.'); }
    // Draft artifacts must be verified before publication. The runtime still rejects real drafts.
    $release['draft'] = false;
    $keys = require $argv[3];
    $manifest = \SWGallery\WordPress\Update\Manifest::verify(
        (string) file_get_contents($argv[2] . '/sw-plugin-release.json'),
        (string) file_get_contents($argv[2] . '/sw-plugin-release.json.sig'), $keys, $release
    );
    if ($manifest instanceof WP_Error) { throw new RuntimeException($manifest->message); }
    foreach ($manifest['packages'] as $package) {
        $result = \SWGallery\WordPress\Update\Package::verify($argv[2] . '/' . $package['filename'], $package);
        if ($result instanceof WP_Error) { throw new RuntimeException($result->message); }
        // Validate the keyring actually embedded in this ZIP without executing it.
        $zip = new ZipArchive();
        if ($zip->open($argv[2] . '/' . $package['filename']) !== true) { throw new RuntimeException('Cannot open package.'); }
        $ring = $zip->getFromName($package['slug'] . '/packages/wordpress/src/Update/Config/trusted-keys.php');
        $zip->close();
        foreach ($keys as $key) {
            if (!is_string($ring) || !str_contains($ring, $key)) { throw new RuntimeException('Embedded public keyring does not contain the trusted build keys.'); }
        }
    }
    echo "Signed release and all packages verified.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Artifact verification failed: ' . $error->getMessage() . "\n"); exit(1);
}
