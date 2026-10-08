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
     * ✅ Upload from remote URL — with SKIP on missing file
     */
    // public function uploadDocumentFromUrl(
    // string $entityType,
    // int $entityId,
    // string $fileUrl,
    // string $name,
    // ?string $description = null,
    // ?bool $isKyc = null,
    // ?int $kycDocumentTypeId = null,
    // ?int $tag = null,
    // ?int $borrowerId = null
    // ): array {
    //     // ✅ Log create
    //     $log = GravitonApplicationLog::create([
    //         'borrower_id'      => $borrowerId,
    //         'step'             => 'document_upload',
    //         'entity_type'      => $entityType,
    //         'endpoint'         => "/{$entityType}/{$entityId}/documents",
    //         'status'           => 'pending',
    //         'request_payload'  => [
    //             'fileUrl'    => $fileUrl,
    //             'name'       => $name,
    //             'entityType' => $entityType,
    //             'entityId'   => $entityId,
    //         ],
    //         'requested_at'     => now(),
    //     ]);

    //     try {
    //         // ✅ Download file — cURL
    //         $content = $this->downloadFile($fileUrl);

    //         if ($content === null) {
    //             $log->update([
    //                 'status'        => 'skipped',
    //                 'error_message' => 'File not found on server — skipped',
    //                 'completed_at'  => now(),
    //             ]);
    //             return ['status' => 'skipped', 'reason' => 'file_not_found'];
    //         }

    //         $mimeType = $this->guessMimeType($fileUrl);
    //         $filename = basename(parse_url($fileUrl, PHP_URL_PATH));

    //         $multipart = [
    //             [
    //                 'name'     => 'file',
    //                 'contents' => $content,
    //                 'filename' => $filename,
    //                 'headers'  => ['Content-Type' => $mimeType],
    //             ],
    //             ['name' => 'name', 'contents' => $name],
    //         ];

    //         if ($description !== null) {
    //             $multipart[] = ['name' => 'description', 'contents' => $description];
    //         }
    //         if ($isKyc !== null) {
    //             $multipart[] = ['name' => 'isKyc', 'contents' => $isKyc ? 'true' : 'false'];
    //         }
    //         if ($kycDocumentTypeId !== null) {
    //             $multipart[] = ['name' => 'kycDocumentTypeId', 'contents' => (string) $kycDocumentTypeId];
    //         }
    //         if ($tag !== null) {
    //             $multipart[] = ['name' => 'tag', 'contents' => (string) $tag];
    //         }

    //         // ✅ FORCE multipart/form-data
    //         $response = $this->auth->client()
    //             ->withHeaders([
    //                 'Content-Type' => 'multipart/form-data',
    //             ])
    //             ->asMultipart()
    //             ->post("/{$entityType}/{$entityId}/documents", $multipart);

    //         $data = $response->json();

    //         $log->update([
    //             'response_payload' => $data,
    //             'http_status'      => $response->status(),
    //         ]);

    //         if (!$response->successful()) {
    //             $log->update([
    //                 'status'        => 'failed',
    //                 'error_message' => 'HTTP ' . $response->status() . ': ' . json_encode($data),
    //                 'completed_at'  => now(),
    //             ]);
    //             throw new \Exception('Upload failed: HTTP ' . $response->status());
    //         }

    //         $log->update([
    //             'status'       => 'success',
    //             'resource_id'  => $data['resourceId'] ?? null,
    //             'completed_at' => now(),
    //         ]);

    //         return $data;

    //     } catch (\Throwable $e) {
    //         $log->update([
    //             'status'        => 'failed',
    //             'error_message' => $e->getMessage(),
    //             'completed_at'  => now(),
    //         ]);
    //         throw $e;
    //     }
    // }

    /**
 * ✅ Upload document from remote URL
 *
 * Flow:
 * 1. Download file via cURL
 * 2. Save to temp file
 * 3. Upload as multipart/form-data
 * 4. Log success/failure
 */
    // public function uploadDocumentFromUrl(
    // string $entityType,
    // int $entityId,
    // string $fileUrl,
    // string $name,
    // ?string $description = null,
    // ?bool $isKyc = null,
    // ?int $kycDocumentTypeId = null,
    // ?int $tag = null,
    // ?int $borrowerId = null
    // ): array {
    //     $log = GravitonApplicationLog::create([
    //         'borrower_id'      => $borrowerId,
    //         'step'             => 'document_upload',
    //         'entity_type'      => $entityType,
    //         'endpoint'         => "/{$entityType}/{$entityId}/documents",
    //         'status'           => 'pending',
    //         'request_payload'  => [
    //             // 'fileUrl'    => $fileUrl,
    //             'name'       => $name,
    //             'entityType' => $entityType,
    //             'entityId'   => $entityId,
    //         ],
    //         'requested_at'     => now(),
    //     ]);

    //     $tempFile = null;

    //     try {
    //         // Download
    //         $content = $this->downloadFile($fileUrl);
    //         if ($content === null) {
    //             $log->update([
    //                 'status'        => 'skipped',
    //                 'error_message' => 'File not found on server — skipped',
    //                 'completed_at'  => now(),
    //             ]);
    //             return ['status' => 'skipped', 'reason' => 'file_not_found'];
    //         }

    //         $mimeType = $this->guessMimeType($fileUrl);
    //         $filename = basename(parse_url($fileUrl, PHP_URL_PATH));

    //         // Temp file
    //         $tempFile = tmpfile();
    //         fwrite($tempFile, $content);
    //         $tempPath = stream_get_meta_data($tempFile)['uri'];

    //         $multipart = [
    //             [
    //                 'name'     => 'file',
    //                 'contents' => fopen($tempPath, 'r'),
    //                 'filename' => $filename,
    //                 'headers'  => ['Content-Type' => $mimeType],
    //             ],
    //             ['name' => 'name', 'contents' => $name],
    //         ];

    //         if ($description !== null) {
    //             $multipart[] = ['name' => 'description', 'contents' => $description];
    //         }
    //         if ($isKyc !== null) {
    //             $multipart[] = ['name' => 'isKyc', 'contents' => $isKyc ? 'true' : 'false'];
    //         }
    //         if ($kycDocumentTypeId !== null) {
    //             $multipart[] = ['name' => 'kycDocumentTypeId', 'contents' => (string) $kycDocumentTypeId];
    //         }
    //         if ($tag !== null) {
    //             $multipart[] = ['name' => 'tag', 'contents' => (string) $tag];
    //         }

    //         // ✅ FIX — Content-Type manual set korbe na
    //         // Laravel asMultipart() auto set korbe boundary soho
    //         $response = $this->auth->client()
    //             ->asMultipart()
    //             ->post("/{$entityType}/{$entityId}/documents", $multipart);

    //         $data = $response->json();

    //         $log->update([
    //             'response_payload' => $data,
    //             'http_status'      => $response->status(),
    //         ]);

    //         if (!$response->successful()) {
    //             $log->update([
    //                 'status'        => 'failed',
    //                 'error_message' => 'HTTP ' . $response->status() . ': ' . json_encode($data),
    //                 'completed_at'  => now(),
    //             ]);
    //             throw new \Exception('Upload failed: HTTP ' . $response->status());
    //         }

    //         $log->update([
    //             'status'       => 'success',
    //             'resource_id'  => $data['resourceId'] ?? null,
    //             'completed_at' => now(),
    //         ]);

    //         return $data;

    //     } catch (\Throwable $e) {
    //         $log->update([
    //             'status'        => 'failed',
    //             'error_message' => $e->getMessage(),
    //             'completed_at'  => now(),
    //         ]);
    //         throw $e;
    //     } finally {
    //         if ($tempFile !== null) {
    //             fclose($tempFile);
    //         }
    //     }
    // }

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
        // ✅ Step 1: Download bytes
        $content = $this->downloadFile($fileUrl);
        if ($content === null) {
            GravitonApplicationLog::create([
                'borrower_id'      => $borrowerId,
                'step'             => 'document_upload',
                'entity_type'      => $entityType,
                'endpoint'         => "/{$entityType}/{$entityId}/documents",
                'status'           => 'skipped',
                'error_message'    => 'File not found on server — skipped',
                'request_payload'  => [
                    'name'       => $name,
                    'entityType' => $entityType,
                    'entityId'   => $entityId,
                ],
                'requested_at'     => now(),
                'completed_at'     => now(),
            ]);
            return ['status' => 'skipped', 'reason' => 'file_not_found'];
        }

        // ✅ Step 2: fileUrl theke path nao
        $queryString = parse_url($fileUrl, PHP_URL_QUERY);
        parse_str($queryString, $queryParams);
        $path = $queryParams['path'] ?? '';

        // ✅ Path theke filename nao
        $filename = basename($path);   // ✅ PAN_CARD.jpg

        // ✅ Filename theke MIME type
        $mimeType = $this->guessMimeType($filename);

        // ✅ Step 3: Log create
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
            ],
            'requested_at'     => now(),
        ]);

        $tempFile = null;

        try {
            // ✅ Step 4: Temp file
            $tempFile = tmpfile();
            fwrite($tempFile, $content);
            $tempPath = stream_get_meta_data($tempFile)['uri'];

            // ✅ Step 5: Multipart
            $multipart = [
                ['name' => 'name', 'contents' => $name],
            ];

            if ($description !== null) {
                $multipart[] = ['name' => 'description', 'contents' => $description];
            }

            $multipart[] = [
                'name'     => 'file',
                'contents' => fopen($tempPath, 'r'),
                'filename' => $filename,        // ✅ PAN_CARD.jpg
                'headers'  => ['Content-Type' => $mimeType],  // ✅ image/jpeg
            ];

            // ✅ Step 6: API call
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
                    'error_message' => 'HTTP ' . $response->status() . ': ' . json_encode($data),
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
            if ($tempFile !== null) {
                fclose($tempFile);
            }
        }
    }

    /**
     * ✅ MIME type guess — filename theke
     */
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

    /**
     * ✅ Bytes to human readable
     */
    protected function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
        if ($bytes >= 1024)    return round($bytes / 1024, 2) . ' KB';
        return $bytes . ' B';
    }

    /**
     * ✅ Download file with cURL
     */
    protected function downloadFile(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        $content  = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // ✅ File na thakle null return
        if ($content === false || $httpCode !== 200) {
            Log::warning('File not available', [
                'url'       => $url,
                'http_code' => $httpCode,
                'response'  => substr($content ?: '', 0, 200),
            ]);
            return null;
        }

        return $content;
    }

    /**
     * ✅ MIME type guess
     */
    // protected function guessMimeType(string $url): string
    // {
    //     $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

    //     return match ($ext) {
    //         'jpg', 'jpeg' => 'image/jpeg',
    //         'png'         => 'image/png',
    //         'gif'         => 'image/gif',
    //         'pdf'         => 'application/pdf',
    //         default       => 'application/octet-stream',
    //     };
    // }
}