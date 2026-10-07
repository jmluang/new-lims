<?php

namespace App\Services\Pdf;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

/** Create only missing private PDF directories; never rewrite an existing owner's ACL. */
final class PdfStorageDirectory
{
    public function ensure(string $directory, ?string $root = null): void
    {
        $root = rtrim($root ?? Storage::disk('pdf')->path(''), DIRECTORY_SEPARATOR);
        $directory = rtrim($directory, DIRECTORY_SEPARATOR);
        if ($root === '' || $directory === '' || ! str_starts_with($directory, $root.DIRECTORY_SEPARATOR)
            || ! is_dir($root) || is_link($root)) {
            throw new RuntimeException('PDF directory is outside its private storage root.');
        }

        $cursor = $root;
        foreach (explode(DIRECTORY_SEPARATOR, substr($directory, strlen($root) + 1)) as $component) {
            if ($component === '' || $component === '.' || $component === '..') {
                throw new RuntimeException('PDF directory component is invalid.');
            }
            $cursor .= DIRECTORY_SEPARATOR.$component;
            if (is_link($cursor)) {
                throw new RuntimeException('PDF directory must not be a symbolic link.');
            }
            if (is_dir($cursor)) {
                continue;
            }
            if (! @mkdir($cursor, 0770) && ! is_dir($cursor)) {
                throw new RuntimeException('Unable to create private PDF directory.');
            }
            // mkdir is subject to the worker's umask; chmod after creation also
            // guarantees setgid inheritance for the next directory and its files.
            if (! @chmod($cursor, 02770)) {
                throw new RuntimeException('Unable to set private PDF directory permissions.');
            }
        }
    }
}
