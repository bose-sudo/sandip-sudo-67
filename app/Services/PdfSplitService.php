<?php

declare(strict_types=1);

namespace App\Services;

use Exception;

class PdfSplitService
{
    private const AGREEMENT_PAGES = 42;

    public function splitIfRequired(
        string $borrowerId,
        string $fileName = 'final_signed_agreement.pdf'
    ): array {
        $this->assertBinaries();

        $directory  = storage_path("app/public/e-sign/{$borrowerId}/BORROWER");
        $sourceFile = $directory . DIRECTORY_SEPARATOR . $fileName;
        $backupFile = $directory . DIRECTORY_SEPARATOR
            . pathinfo($fileName, PATHINFO_FILENAME) . '_original_copy.pdf';
        $consentFile = $directory . DIRECTORY_SEPARATOR
            . "consent-letter-for-sharing-data-{$borrowerId}.pdf";

        if (!is_file($sourceFile)) {
            throw new Exception("PDF not found: {$sourceFile}");
        }

        if (!is_writable($directory)) {
            throw new Exception("Directory not writable: {$directory}");
        }

        $pageCount = $this->getPageCount($sourceFile);

        if ($pageCount <= self::AGREEMENT_PAGES) {
            return [
                'success'    => true,
                'split'      => false,
                'page_count' => $pageCount,
                'message'    => 'PDF does not require splitting.',
            ];
        }

        // Idempotency: a prior run leaves a backup behind. Re-split from it.
        if (is_file($backupFile)) {
            @unlink($backupFile);
        }

        if (!rename($sourceFile, $backupFile)) {
            throw new Exception('Unable to rename original PDF.');
        }

        try {
            $this->splitPdf($backupFile, $sourceFile, 1, self::AGREEMENT_PAGES);
            $this->splitPdf($backupFile, $consentFile, self::AGREEMENT_PAGES + 1, $pageCount);
        } catch (Exception $e) {
            // Rollback: never leave the borrower without final_signed_agreement.pdf
            @unlink($sourceFile);
            @unlink($consentFile);
            @rename($backupFile, $sourceFile);
            throw $e;
        }

        @chmod($sourceFile, 0644);
        @chmod($consentFile, 0644);
        @chmod($backupFile, 0644);

        return [
            'success'    => true,
            'split'      => true,
            'page_count' => $pageCount,
            'agreement'  => $sourceFile,
            'consent'    => $consentFile,
            'backup'     => $backupFile,
        ];
    }

    protected function assertBinaries(): void
    {
        if (!function_exists('exec') || in_array('exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)) {
            throw new Exception('exec() is disabled; cannot split PDF.');
        }

        foreach (['pdfinfo', 'gs'] as $bin) {
            exec('command -v ' . escapeshellarg($bin), $out, $status);
            if ($status !== 0) {
                throw new Exception("Required binary not found: {$bin}");
            }
        }
    }

    protected function getPageCount(string $file): int
    {
        exec('pdfinfo ' . escapeshellarg($file) . ' 2>&1', $output, $status);

        if ($status !== 0) {
            throw new Exception('Unable to read PDF information: ' . implode(' ', $output));
        }

        foreach ($output as $line) {
            if (preg_match('/^Pages:\s+(\d+)/', $line, $m)) {
                return (int) $m[1];
            }
        }

        throw new Exception('Unable to determine page count.');
    }

    protected function splitPdf(string $input, string $output, int $firstPage, int $lastPage): void
    {
        $command = sprintf(
            'gs -q -dNOPAUSE -dBATCH -dSAFER -sDEVICE=pdfwrite -dFirstPage=%d -dLastPage=%d -sOutputFile=%s %s 2>&1',
            $firstPage,
            $lastPage,
            escapeshellarg($output),
            escapeshellarg($input)
        );

        exec($command, $result, $status);

        if ($status !== 0 || !is_file($output) || filesize($output) === 0) {
            throw new Exception('Ghostscript failed while splitting PDF: ' . implode(' ', $result));
        }
    }
}