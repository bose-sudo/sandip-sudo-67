<?php

namespace App\Services\Graviton;

use App\Models\GravitonApplicationLog;
use Illuminate\Support\Facades\Log;

class GravitonDocumentUploadService
{
    public function __construct(
        private GravitonAuthService $auth
    ) {}

    /**
     * ✅ Upload from remote URL
     */
    public function uploadDocumentFromUrl(
        string $entityType,
        int $entityId,
        string $fileUrl,
        string $name,
        ?string $description = null,
        ?bool $isKyc = null,
        ?int $kycDocumentTypeId = null,
        ?int $tag = null,
        ?int $borrowerId = null
    ): array {
        $content = $this->downloadFile($fileUrl);
        if ($content === null) {
            GravitonApplicationLog::create([
                'borrower_id'      => $borrowerId,
                'step'             => 'document_upload',
                'entity_type'      => $entityType,
                'endpoint'         => "/{$entityType}/{$entityId}/documents",
                'status'           => 'skipped',
                'error_message'    => 'File not found — skipped',
                'request_payload'  => ['name' => $name],
                'requested_at'     => now(),
                'completed_at'     => now(),
            ]);
            return ['status' => 'skipped', 'reason' => 'file_not_found'];
        }

        $queryString = parse_url($fileUrl, PHP_URL_QUERY);
        parse_str($queryString, $queryParams);
        $filename = basename($queryParams['path'] ?? '');
        $mimeType = $this->guessMimeType($filename);

        $log = GravitonApplicationLog::create([
            'borrower_id'      => $borrowerId,
            'step'             => 'document_upload',
            'entity_type'      => $entityType,
            'endpoint'         => "/{$entityType}/{$entityId}/documents",
            'status'           => 'pending',
            'request_payload'  => [
                'name'              => $name,
                'description'       => $description,
                'filename'          => $filename,
                'mimeType'          => $mimeType,
                'fileSize'          => strlen($content),
                'fileSizeFormatted' => $this->formatBytes(strlen($content)),
                'fileHash'          => hash('sha256', $content),
                'entityType'        => $entityType,
                'entityId'          => $entityId,
                'isKyc'             => $isKyc,
                'kycDocumentTypeId' => $kycDocumentTypeId,
                'source'            => 'remote_url',
            ],
            'requested_at'     => now(),
        ]);

        $tempFile = null;

        try {
            $tempFile = tmpfile();
            fwrite($tempFile, $content);
            $tempPath = stream_get_meta_data($tempFile)['uri'];

            $multipart = [['name' => 'name', 'contents' => $name]];
            if ($description !== null) {
                $multipart[] = ['name' => 'description', 'contents' => $description];
            }
            $multipart[] = [
                'name'     => 'file',
                'contents' => fopen($tempPath, 'r'),
                'filename' => $filename,
                'headers'  => ['Content-Type' => $mimeType],
            ];
            if ($isKyc !== null) {
                $multipart[] = ['name' => 'isKyc', 'contents' => $isKyc ? 'true' : 'false'];
            }
            if ($kycDocumentTypeId !== null) {
                $multipart[] = ['name' => 'kycDocumentTypeId', 'contents' => (string) $kycDocumentTypeId];
            }
            if ($tag !== null) {
                $multipart[] = ['name' => 'tag', 'contents' => (string) $tag];
            }

            $response = $this->auth->client()
                ->asMultipart()
                ->post("/{$entityType}/{$entityId}/documents", $multipart);

            $data = $response->json();

            $log->update([
                'response_payload' => $data,
                'http_status'      => $response->status(),
            ]);

            if (!$response->successful()) {
                $log->update([
                    'status'        => 'failed',
                    'error_message' => 'HTTP ' . $response->status(),
                    'completed_at'  => now(),
                ]);
                throw new \Exception('Upload failed: HTTP ' . $response->status());
            }

            $log->update([
                'status'       => 'success',
                'resource_id'  => $data['resourceId'] ?? null,
                'completed_at' => now(),
            ]);

            return $data;

        } catch (\Throwable $e) {
            $log->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at'  => now(),
            ]);
            throw $e;
        } finally {
            if ($tempFile !== null) fclose($tempFile);
        }
    }

    /**
     * ✅ Upload from LOCAL file path (CIBIL PDF etc.)
     */
    public function uploadDocumentFromPath(
        string $entityType,
        int $entityId,
        string $filePath,
        string $name,
        ?string $description = null,
        ?int $borrowerId = null
    ): array {
        if (!file_exists($filePath)) {
            Log::warning('File not found — skipped', ['path' => $filePath]);
            return ['status' => 'skipped', 'reason' => 'file_not_found'];
        }

        $filename = basename($filePath);
        $mimeType = mime_content_type($filePath) ?: 'application/octet-stream';
        $fileSize = filesize($filePath);

        $log = GravitonApplicationLog::create([
            'borrower_id'      => $borrowerId,
            'step'             => 'document_upload',
            'entity_type'      => $entityType,
            'endpoint'         => "/{$entityType}/{$entityId}/documents",
            'status'           => 'pending',
            'request_payload'  => [
                'name'              => $name,
                'description'       => $description,
                'filename'          => $filename,
                'mimeType'          => $mimeType,
                'fileSize'          => $fileSize,
                'fileSizeFormatted' => $this->formatBytes($fileSize),
                'fileHash'          => hash_file('sha256', $filePath),
                'entityType'        => $entityType,
                'entityId'          => $entityId,
                'source'            => 'local_path',
            ],
            'requested_at'     => now(),
        ]);

        try {
            $multipart = [['name' => 'name', 'contents' => $name]];
            if ($description !== null) {
                $multipart[] = ['name' => 'description', 'contents' => $description];
            }
            $multipart[] = [
                'name'     => 'file',
                'contents' => fopen($filePath, 'r'),
                'filename' => $filename,
                'headers'  => ['Content-Type' => $mimeType],
            ];

            $response = $this->auth->client()
                ->asMultipart()
                ->post("/{$entityType}/{$entityId}/documents", $multipart);

            $data = $response->json();

            $log->update([
                'response_payload' => $data,
                'http_status'      => $response->status(),
            ]);

            if (!$response->successful()) {
                $log->update([
                    'status'        => 'failed',
                    'error_message' => 'HTTP ' . $response->status(),
                    'completed_at'  => now(),
                ]);
                throw new \Exception('Upload failed: HTTP ' . $response->status());
            }

            $log->update([
                'status'       => 'success',
                'resource_id'  => $data['resourceId'] ?? null,
                'completed_at' => now(),
            ]);

            return $data;

        } catch (\Throwable $e) {
            $log->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at'  => now(),
            ]);
            throw $e;
        }
    }

    /**
     * ✅ Upload image (clients/familymember/{id}/images)
     */
    public function uploadImageFromUrl(string $entity, int $entityId, string $fileUrl): array
    {
        $content = $this->downloadFile($fileUrl);
        if ($content === null) {
            Log::warning('Image not found', ['url' => $fileUrl]);
            return ['status' => 'skipped', 'reason' => 'file_not_found'];
        }

        $queryString = parse_url($fileUrl, PHP_URL_QUERY);
        parse_str($queryString, $queryParams);
        $filename = basename($queryParams['path'] ?? '');
        $mimeType = $this->guessMimeType($filename);

        if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/gif'])) {
            Log::warning('Invalid image type', ['mime' => $mimeType]);
            return ['status' => 'skipped', 'reason' => 'invalid_image_type'];
        }

        $tempFile = tmpfile();
        fwrite($tempFile, $content);
        $tempPath = stream_get_meta_data($tempFile)['uri'];

        $multipart = [[
            'name'     => 'file',
            'contents' => fopen($tempPath, 'r'),
            'filename' => $filename,
            'headers'  => ['Content-Type' => $mimeType],
        ]];

        try {
            $response = $this->auth->client()
                ->asMultipart()
                ->post("/{$entity}/{$entityId}/images", $multipart);

            $data = $response->json();

            if (!$response->successful()) {
                throw new \Exception('Image upload failed: HTTP ' . $response->status());
            }

            Log::info('Image uploaded', [
                'entity'   => $entity,
                'entityId' => $entityId,
                'filename' => $filename,
            ]);

            return $data;
        } finally {
            fclose($tempFile);
        }
    }

    protected function downloadFile(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        $content  = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($content === false || $httpCode !== 200 || strlen($content) === 0) {
            Log::warning('File not available', ['url' => $url, 'http_code' => $httpCode]);
            return null;
        }

        return $content;
    }

    protected function guessMimeType(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png'         => 'image/png',
            'gif'         => 'image/gif',
            'pdf'         => 'application/pdf',
            default       => 'application/octet-stream',
        };
    }

    protected function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
        if ($bytes >= 1024)    return round($bytes / 1024, 2) . ' KB';
        return $bytes . ' B';
    }
}