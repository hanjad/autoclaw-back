<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

// Turns a stored file path into a URL the browser can load
class Media
{
    public static function url(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        $name = config('marketplace.public_disk');

        // Local disks are served through the public/storage symlink on whatever host the request came in on
        if (config("filesystems.disks.{$name}.driver") === 'local') {
            return asset('storage/' . $path);
        }

        return Storage::disk($name)->url($path);
    }
}
