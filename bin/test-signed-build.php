<?php
/** PR/local artifact regression. Disposable keys only; no GitHub or live WordPress writes. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }
final class WP_Error {
    public function __construct(public string $code, public string $message, public mixed $data = null) {}
}
function __(string $text, string $domain): string { return $text; }
require dirname(__DIR__) . '/packages/wordpress/src/Update/Manifest.php';
require dirname(__DIR__) . '/packages/wordpress/src/Update/Package.php';
$dir = sys_get_temp_dir() . '/swg-signed-build-' . bin2hex(random_bytes(8));
mkdir($dir, 0700);
try {
    $zip = $argv[1] ?? '';
    if (!is_file($zip) || !preg_match('/^sw-gallery-(\d+\.\d+\.\d+)\.zip$/D', basename($zip), $match)) { throw new RuntimeException('Built release ZIP required.'); }
    $name = basename($zip); copy($zip, $dir . '/' . $name);
    $release = ['id'=>42,'tag_name'=>'v'.$match[1],'draft'=>true,'prerelease'=>false,'assets'=>[['id'=>55,'name'=>$name,'size'=>filesize($zip)]]];
    file_put_contents($dir . '/release.json', json_encode($release, JSON_THROW_ON_ERROR));
    $pair = sodium_crypto_sign_keypair();
    $secret = sodium_crypto_sign_secretkey($pair);
    $public = sodium_crypto_sign_publickey($pair);
    $run = static function (array $env) use ($dir): int {
        $process = proc_open([PHP_BINARY, __DIR__ . '/release-manifest.php', 'sign', $dir . '/release.json', $dir, str_repeat('a',40)],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['redirect',1]],$pipes,null,$env);
        if (!is_resource($process)) { throw new RuntimeException('Cannot run signer.'); }
        fclose($pipes[0]); $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $code = proc_close($process);
        if ($code !== 0 && $env) { throw new RuntimeException('Signing build failed: '.$output); }
        return $code;
    };
    if ($run([]) === 0 || file_exists($dir.'/sw-plugin-release.json')) { throw new RuntimeException('Missing secret must abort.'); }
    $run(['SW_PLUGIN_RELEASE_SIGNING_KEY'=>base64_encode($secret),'SW_PLUGIN_RELEASE_KEY_ID'=>'disposable-build']);
    $release['draft'] = false;
    $raw = file_get_contents($dir.'/sw-plugin-release.json'); $signature = file_get_contents($dir.'/sw-plugin-release.json.sig');
    $manifest = \SWGallery\WordPress\Update\Manifest::verify($raw,$signature,['disposable-build'=>base64_encode($public)],$release);
    if ($manifest instanceof WP_Error) { throw new RuntimeException($manifest->message); }
    $package = $manifest['packages'][0];
    if (\SWGallery\WordPress\Update\Package::verify($dir.'/'.$name,$package) !== true) { throw new RuntimeException('Build failed runtime package verification.'); }
    // Exercise the protected workflow's embedded-key artifact verifier on a disposable copy too.
    $ringPath = $dir . '/trusted-keys.php';
    $keys = require dirname(__DIR__) . '/packages/wordpress/src/Update/Config/trusted-keys.php';
    $keys['disposable-build'] = base64_encode($public);
    $ring = '<?php return ' . var_export($keys, true) . ';';
    file_put_contents($ringPath, $ring);
    $archive = new ZipArchive();
    if ($archive->open($dir.'/'.$name) !== true) { throw new RuntimeException('Cannot open build copy.'); }
    $archive->addFromString('sw-gallery/packages/wordpress/src/Update/Config/trusted-keys.php', $ring);
    $archive->close();
    clearstatcache(true, $dir.'/'.$name);
    $release['draft'] = true;
    $release['assets'][0]['size'] = filesize($dir.'/'.$name);
    file_put_contents($dir.'/release.json', json_encode($release, JSON_THROW_ON_ERROR));
    $run(['SW_PLUGIN_RELEASE_SIGNING_KEY'=>base64_encode($secret),'SW_PLUGIN_RELEASE_KEY_ID'=>'disposable-build']);
    $process = proc_open([PHP_BINARY, __DIR__.'/verify-signed-release.php', $dir.'/release.json', $dir, $ringPath],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['redirect',1]], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Cannot run artifact verifier.'); }
    fclose($pipes[0]); $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
    if (proc_close($process) !== 0) { throw new RuntimeException('Embedded-key artifact validation failed: '.$output); }
    // Restore the exact original package so its original signature/hash still define the tamper test below.
    copy($zip, $dir.'/'.$name);
    file_put_contents($dir.'/'.$name, 'tamper', FILE_APPEND);
    if (!\SWGallery\WordPress\Update\Package::verify($dir.'/'.$name,$package) instanceof WP_Error) { throw new RuntimeException('Tampering was accepted.'); }
    echo "Real build signed with disposable key; runtime signature/package verification, tampering rejection and missing-secret abort passed.\n";
} finally {
    if (isset($secret)) { sodium_memzero($secret); }
    if (isset($pair)) { sodium_memzero($pair); }
    foreach (glob($dir.'/*') ?: [] as $file) { unlink($file); } rmdir($dir);
}
