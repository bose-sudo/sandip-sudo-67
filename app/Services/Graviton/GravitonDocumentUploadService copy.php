<?php

namespace App\Services\Graviton;

use Illuminate\Support\Facades\Log;

class GravitonDocumentUploadService
{
    public function __construct(
        private GravitonAuthService $auth
    ) {}

    /**
     * ✅ Upload from local file path
     */
    public function uploadDocument(
        string $entityType,
        int $entityId,
        string $filePath,
        string $name,
        ?string $description = null,
        ?bool $isKyc = null,
        ?int $kycDocumentTypeId = null,
        ?int $tag = null
    ): array {
        if (!file_exists($filePath)) {
            throw new \Exception("File not found: {$filePath}");
        }

        $multipart = [
            [
                'name'     => 'file',
                'contents' => fopen($filePath, 'r'),
                'filename' => basename($filePath),
                'headers'  => [
                    'Content-Type' => mime_content_type($filePath) ?: 'application/octet-stream',
                ],
            ],
            ['name' => 'name', 'contents' => $name],
        ];

        return $this->sendUpload(
            $entityType, $entityId, $multipart,
            $description, $isKyc, $kycDocumentTypeId, $tag
        );
    }

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
        ?int $tag = null
    ): array {
        // Download file content
        $content = @file_get_contents($fileUrl);
        if ($content === false) {
            throw new \Exception("Failed to download file: {$fileUrl}");
        }

        $mimeType = $this->guessMimeType($fileUrl);
        $filename = basename(parse_url($fileUrl, PHP_URL_PATH));

        $multipart = [
            [
                'name'     => 'file',
                'contents' => $content,
                'filename' => $filename,
                'headers'  => ['Content-Type' => $mimeType],
            ],
            ['name' => 'name', 'contents' => $name],
        ];

        return $this->sendUpload(
            $entityType, $entityId, $multipart,
            $description, $isKyc, $kycDocumentTypeId, $tag
        );
    }

    /**
     * ✅ Common upload logic
     */
    protected function sendUpload(
        string $entityType,
        int $entityId,
        array $multipart,
        ?string $description,
        ?bool $isKyc,
        ?int $kycDocumentTypeId,
        ?int $tag
    ): array {
        if ($description !== null) {
            $multipart[] = ['name' => 'description', 'contents' => $description];
        }
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

        if (!$response->successful()) {
            throw new \Exception(
                'Upload failed: HTTP ' . $response->status() . ' — ' . $response->body()
            );
        }

        Log::info('Document uploaded', [
            'entityType' => $entityType,
            'entityId'   => $entityId,
            'resourceId' => $data['resourceId'] ?? null,
        ]);

        return $data;
    }

    /**
     * ✅ MIME type guess koro URL theke
     */
    protected function guessMimeType(string $url): string
    {
        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png'         => 'image/png',
            'gif'         => 'image/gif',
            'pdf'         => 'application/pdf',
            default       => 'application/octet-stream',
        };
    }
}