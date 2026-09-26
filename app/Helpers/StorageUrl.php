<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Storage;

class StorageUrl
{
    /**
     * Return the correct public URL for a stored file,
     * whether it's on the local 'public' disk or an S3 bucket.
     */
    public static function url(?string $path): string
    {
        if (!$path) {
            return '';
        }

        if (str_starts_with($path, 'signatures/') || str_starts_with($path, 'receipts/')
            || str_starts_with($path, 'attendance-photos/') || str_starts_with($path, 'attendance/')) {
            return route('storage.s3', ['path' => $path]);
        }

        // If file exists on local public storage disk, return local asset URL
        if (\Illuminate\Support\Facades\Storage::disk('public')->exists($path)) {
            return asset('storage/' . $path);
        }

        $disk = config('filesystems.default', 'local');

        // FORCE S3 proxy for paths known to be stored in S3 or when default disk is s3
        if ($disk === 's3' || str_starts_with($path, 'signatures/') || str_starts_with($path, 'posters/') || str_starts_with($path, 'attendance/') || str_starts_with($path, 'attendance-photos/')) {
            return route('storage.s3', ['path' => $path]);
        }

        // Fallback: local public disk
        return asset('storage/' . $path);
    }
}
