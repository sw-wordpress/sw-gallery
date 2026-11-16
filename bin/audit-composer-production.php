<?php

declare(strict_types=1);

// Composer exits unsuccessfully for an empty production package set. Only that known case is a no-op;
// malformed/missing locks and every error from a nonempty audit must still fail the gate.
try {
    $lockFile = getcwd() . '/composer.lock';
    if (!is_readable($lockFile)) {
        throw new RuntimeException('composer.lock is missing or unreadable.');
    }
    $lock = json_decode((string) file_get_contents($lockFile), false, 512, JSON_THROW_ON_ERROR);
    if (!$lock instanceof stdClass || !isset($lock->packages) || !is_array($lock->packages) || !array_is_list($lock->packages)) {
        throw new RuntimeException('composer.lock must contain a production packages list.');
    }
    if ($lock->packages === []) {
        echo in_array('--format=json', $argv, true)
            ? "{\"advisories\":[],\"abandoned\":[],\"message\":\"No production Composer packages to audit.\"}\n"
            : "No production Composer packages to audit.\n";
        exit(0);
    }
    $process = proc_open(['composer', 'audit', '--locked', '--no-dev', ...array_slice($argv, 1)], [STDIN, STDOUT, STDERR], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start Composer audit.');
    }
    exit(proc_close($process));
} catch (Throwable $error) {
    fwrite(STDERR, 'Composer production audit failed: ' . $error->getMessage() . "\n");
    exit(1);
}
