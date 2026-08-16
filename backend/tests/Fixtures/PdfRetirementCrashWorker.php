<?php

$action = $argv[1] ?? '';
$source = $argv[2] ?? '';
$target = $argv[3] ?? '';

$syncDirectory = static function (string $directory): void {
    if (! function_exists('fsync')) {
        return;
    }
    $handle = @fopen($directory, 'r');
    if ($handle !== false) {
        fsync($handle);
        fclose($handle);
    }
};

if ($action === 'move') {
    $targetDirectory = dirname($target);
    if (! is_dir($targetDirectory) && ! mkdir($targetDirectory, 0770, true) && ! is_dir($targetDirectory)) {
        throw new RuntimeException('Unable to create retirement evidence directory.');
    }
    if (! rename($source, $target)) {
        throw new RuntimeException('Unable to move retirement evidence.');
    }
    $syncDirectory(dirname($source));
    $syncDirectory(dirname($target));
} elseif ($action === 'unlink') {
    if (! unlink($source)) {
        throw new RuntimeException('Unable to unlink retirement evidence.');
    }
    $syncDirectory(dirname($source));
} else {
    throw new InvalidArgumentException('Unknown retirement crash action.');
}

fwrite(STDOUT, "retirement-file-action-durable\n");
fflush(STDOUT);

if (function_exists('posix_kill')) {
    posix_kill(posix_getpid(), 9);
}

exit(137);
