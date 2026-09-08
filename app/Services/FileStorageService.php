<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileStorageService
{
    protected string $primaryDisk = 's3';
    protected string $legacyDisk = 'public';

    protected function legacyModeActive(): bool
    {
        return config('filesystems.default') === 'local';
    }

    protected function normalize(string $path): string
    {
        $path = ltrim($path, '/');
        return ltrim(preg_replace('#^storage/#', '', $path), '/');
    }

    protected function resolveDisk(string $path): ?string
    {
        $normalized = $this->normalize($path);
        $cacheKey = "file_disk:{$normalized}";

        $cached = Cache::get($cacheKey);
        if ($cached) {
            return $cached;
        }

        // this must be enable in production
        // if (Storage::disk($this->primaryDisk)->exists($normalized)) {
        //     Cache::put($cacheKey, $this->primaryDisk, now()->addHours(6));
        //     return $this->primaryDisk;
        // }

        if ($this->legacyModeActive() && Storage::disk($this->legacyDisk)->exists($normalized)) {
            Cache::put($cacheKey, $this->legacyDisk, now()->addHours(6));
            return $this->legacyDisk;
        }

        if (!$this->legacyModeActive()) {
            Log::warning('File not found on S3 and legacy check skipped', ['path' => $path]);
        }

        return null;
    }

    public function store(UploadedFile $file, string $directory, ?string $filename = null): string
    {
        $filename ??= uniqid() . '_' . $file->getClientOriginalName();
        return $file->storeAs($directory, $filename, $this->primaryDisk);
    }

    public function exists(string $path): bool
    {
        return $this->resolveDisk($path) !== null;
    }

    public function url(string $path, int $expiryMinutes = 5): string
    {
        $normalized = $this->normalize($path);
        $disk = $this->resolveDisk($path);

        if ($disk === $this->primaryDisk) {
            /** @var \Illuminate\Filesystem\FilesystemAdapter $s3 */
            $s3 = Storage::disk($this->primaryDisk);
            return $s3->temporaryUrl($normalized, now()->addMinutes($expiryMinutes));
        }

        if ($disk === $this->legacyDisk) {
            return URL::temporarySignedRoute(
                'files.stream',
                now()->addMinutes($expiryMinutes),
                ['path' => $normalized]
            );
        }

        throw new \RuntimeException("File not found: {$path}");
    }

    public function urlOrNull(string $path, int $expiryMinutes = 5): ?string
    {
        try {
            return $this->url($path, $expiryMinutes);
        } catch (\RuntimeException $e) {
            return null;
        }
    }

    public function download(string $path): StreamedResponse
    {
        $normalized = $this->normalize($path);
        $disk = $this->resolveDisk($path);

        if (!$disk) {
            abort(404, 'File not found');
        }

        $filename = basename($normalized);

        if ($disk === $this->primaryDisk) {
            /** @var \Illuminate\Filesystem\FilesystemAdapter $s3 */
            $s3 = Storage::disk($this->primaryDisk);
            $stream = $s3->readStream($normalized);

            return response()->stream(function () use ($stream) {
                fpassthru($stream);
            }, 200, [
                'Content-Type'        => $s3->mimeType($normalized),
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ]);
        }

        /** @var \Illuminate\Filesystem\FilesystemAdapter $legacy */
        $legacy = Storage::disk($this->legacyDisk);
        return $legacy->download($normalized, $filename);
    }

    public function delete(string $path): bool
    {
        $normalized = $this->normalize($path);
        $disk = $this->resolveDisk($path);

        if (!$disk) {
            return false;
        }

        Cache::forget("file_disk:{$normalized}");

        return Storage::disk($disk)->delete($normalized);
    }
}
