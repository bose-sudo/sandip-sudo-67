<?php

namespace App\Services;

use App\Imports\SubventionBulkImport;
use App\Models\LoanBasicDetails;
use App\Models\SubventionDetails;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class SubventionUploadService
{
    // ──────────────────────────────────────────────────────────────
    //  Public: parse file from storage path into raw row collection
    // ──────────────────────────────────────────────────────────────

    public function parseFile(string $storagePath): Collection
    {
        $import = new SubventionBulkImport();
        Excel::import($import, storage_path('app/public/' . $storagePath));

        // Filter out completely blank rows that some Excel versions append
        return $import->rows->filter(function ($row) {
            $vals = array_filter(
                $row->toArray(),
                fn($v) => $v !== null && trim((string) $v) !== ''
            );
            return count($vals) > 0;
        })->values();
    }

    // ──────────────────────────────────────────────────────────────
    //  Public: validate rows — returns ['errors' => [...], 'valid' => [...]]
    //  Each error: ['row' => N, 'field' => '...', 'message' => '...']
    // ──────────────────────────────────────────────────────────────

    public function validateRows(Collection $rows): array
    {
        $errors = [];
        $valid  = [];

        // Pre-fetch existing internal IDs (loan_basic_details.id) that already
        // have a subvention record so we can give a friendly error per row.
        $existingBorrowerIds = SubventionDetails::pluck('borrower_id')->flip();

        foreach ($rows as $index => $row) {
            $rowNum  = $index + 2; // +2 because row 1 is the heading
            $data    = $this->normalizeRow($row->toArray());
            $rowErrs = [];

            // ── Required: borrower_id (Excel value = loan_basic_details.borrower_id e.g. ANC-001) ──
            if (empty($data['borrower_id'])) {
                $rowErrs[] = $this->err($rowNum, 'borrower_id', 'Borrower ID is required');
            } else {
                // Resolve the human-readable borrower_id string to the internal PK
                $loan = LoanBasicDetails::where('borrower_id', $data['borrower_id'])->first();

                if (!$loan) {
                    $rowErrs[] = $this->err($rowNum, 'borrower_id', "Borrower ID {$data['borrower_id']} does not exist");
                } elseif ($existingBorrowerIds->has($loan->id)) {
                    $rowErrs[] = $this->err($rowNum, 'borrower_id', "A subvention record already exists for borrower {$data['borrower_id']}");
                } else {
                    // Replace the human-readable value with the internal PK for insert
                    $data['_borrower_unq'] = $data['borrower_id']; // keep original for error messages
                    $data['borrower_id']   = $loan->id;
                    $data['_loan']         = $loan;
                }
            }

            // ── Subvention block (all required) ───────────────────
            $this->validatePaymentBlock(
                $rowNum, $data,
                'subvention_amount', 'utr_number', 'payment_date', 'bank_name',
                true, // required
                $rowErrs
            );

            // ── RC block (conditional) ────────────────────────────
            $rcFilled = !empty($data['rc_amount']);
            $this->validatePaymentBlock(
                $rowNum, $data,
                'rc_amount', 'rc_utr_number', 'rc_payment_date', 'rc_bank_name',
                $rcFilled,
                $rowErrs
            );

            // ── Cheque block (conditional) ────────────────────────
            $chequeFilled = !empty($data['cheque_amount']);
            $this->validatePaymentBlock(
                $rowNum, $data,
                'cheque_amount', 'cheque_utr_number', 'cheque_bank_name', 'cheque_payment_date',
                $chequeFilled,
                $rowErrs
            );

            if (!empty($rowErrs)) {
                $errors = array_merge($errors, $rowErrs);
            } else {
                $valid[] = $data;
            }
        }

        return ['errors' => $errors, 'valid' => $valid];
    }

    // ──────────────────────────────────────────────────────────────
    //  Public: batch insert validated rows
    // ──────────────────────────────────────────────────────────────

    public function batchInsert(array $validRows, int $authId, string $ip): array
    {
        $now     = now();
        $inserts = [];

        foreach ($validRows as $data) {
            $inserts[] = [
                // borrower_id here is already the internal PK (loan_basic_details.id)
                // resolved from the human-readable borrower_id string during validation
                'borrower_id'              => (int) $data['borrower_id'],
                'subvention_amount'        => $data['subvention_amount'],
                'payment_date'             => $data['payment_date'],
                'utr_number'               => $data['utr_number'],
                'transaction_id'           => $data['transaction_id'] ?? null,
                'bank_name'                => $data['bank_name'],
                'payment_receipt_img'      => null,
                // RC
                'rc_amount'                => $data['rc_amount'] ?? null,
                'rc_utr_number'            => $data['rc_utr_number'] ?? null,
                'rc_payment_date'          => $data['rc_payment_date'] ?? null,
                'rc_bank_name'             => $data['rc_bank_name'] ?? null,
                'rc_payment_receipt_img'   => null,
                // Cheque
                'cheque_amount'            => $data['cheque_amount'] ?? null,
                'cheque_utr_number'        => $data['cheque_utr_number'] ?? null,
                'cheque_payment_date'      => $data['cheque_payment_date'] ?? null,
                'cheque_bank_name'         => $data['cheque_bank_name'] ?? null,
                'cheque_payment_receipt_img' => null,
                // Meta
                'status'                   => 'active',
                'modified_by'              => $authId,
                'ip'                       => $ip,
                'created_at'               => $now,
                'updated_at'               => $now,
            ];
        }

        // Chunk inserts to avoid hitting query size limits
        $chunks = array_chunk($inserts, 100);
        DB::transaction(function () use ($chunks) {
            foreach ($chunks as $chunk) {
                DB::table('subvention_details')->insert($chunk);
            }
        });

        return $inserts;
    }

    // ──────────────────────────────────────────────────────────────
    //  Private helpers
    // ──────────────────────────────────────────────────────────────

    /**
     * Validate one payment block (subvention / rc / cheque).
     * $required = true  → all fields mandatory
     * $required = false → only validate if amount is present
     */
    private function validatePaymentBlock(
        int    $rowNum,
        array  $data,
        string $amountKey,
        string $utrKey,
        string $dateKey,
        string $bankKey,
        bool   $required,
        array  &$errors
    ): void {
        $amount = $data[$amountKey] ?? null;
        $utr    = $data[$utrKey]    ?? null;
        $date   = $data[$dateKey]   ?? null;
        $bank   = $data[$bankKey]   ?? null;

        if (!$required && empty($amount)) {
            return; // optional block not filled — skip
        }

        if (empty($amount) || !is_numeric($amount) || (float) $amount < 0) {
            $errors[] = $this->err($rowNum, $amountKey, ucfirst(str_replace('_', ' ', $amountKey)) . ' must be a valid positive number');
        }

        if (empty($utr)) {
            $errors[] = $this->err($rowNum, $utrKey, ucfirst(str_replace('_', ' ', $utrKey)) . ' is required');
        } elseif (!preg_match('/^[a-zA-Z0-9]+$/', $utr)) {
            $errors[] = $this->err($rowNum, $utrKey, ucfirst(str_replace('_', ' ', $utrKey)) . ' must be alphanumeric');
        }

        if (empty($date)) {
            $errors[] = $this->err($rowNum, $dateKey, ucfirst(str_replace('_', ' ', $dateKey)) . ' is required');
        } else {
            $parsed = $this->parseDate($date);
            if (!$parsed) {
                $errors[] = $this->err($rowNum, $dateKey, ucfirst(str_replace('_', ' ', $dateKey)) . " '$date' is not a recognised date format");
            } elseif ($parsed->isFuture()) {
                $errors[] = $this->err($rowNum, $dateKey, ucfirst(str_replace('_', ' ', $dateKey)) . ' cannot be a future date');
            }
        }

        if (empty($bank)) {
            $errors[] = $this->err($rowNum, $bankKey, ucfirst(str_replace('_', ' ', $bankKey)) . ' is required');
        } elseif (!preg_match('/^[a-zA-Z\s]+$/', $bank)) {
            $errors[] = $this->err($rowNum, $bankKey, ucfirst(str_replace('_', ' ', $bankKey)) . ' must contain only letters');
        }
    }

    /**
     * Normalise a raw Excel row array:
     *  - trim all string values
     *  - strip leading apostrophes Excel adds to protect leading zeros
     *  - convert Excel numeric date serials to Y-m-d strings
     */
    private function normalizeRow(array $raw): array
    {
        $map = [
            'borrower_id'       => 'borrower_id',
            'subvention_amount' => 'subvention_amount',
            'payment_date'      => 'payment_date',
            'utr_number'        => 'utr_number',
            'transaction_id'    => 'transaction_id',
            'bank_name'         => 'bank_name',
            'rc_amount'         => 'rc_amount',
            'rc_utr_number'     => 'rc_utr_number',
            'rc_payment_date'   => 'rc_payment_date',
            'rc_bank_name'      => 'rc_bank_name',
            'cheque_amount'     => 'cheque_amount',
            'cheque_utr_number' => 'cheque_utr_number',
            'cheque_bank_name'  => 'cheque_bank_name',
            'cheque_payment_date' => 'cheque_payment_date',
        ];

        $normalized = [];
        foreach ($map as $excelKey => $appKey) {
            $val = $raw[$excelKey] ?? null;

            if ($val !== null) {
                $val = trim((string) $val);
                // Strip leading apostrophe that users add to preserve leading zeros
                $val = ltrim($val, "'");
                $val = trim($val);
            }

            $normalized[$appKey] = ($val === '') ? null : $val;
        }

        // Parse date columns — Excel may hand us a numeric serial or a string
        foreach (['payment_date', 'rc_payment_date', 'cheque_payment_date'] as $dateCol) {
            if (!empty($normalized[$dateCol])) {
                $parsed = $this->parseDate($normalized[$dateCol]);
                $normalized[$dateCol] = $parsed ? $parsed->format('Y-m-d') : $normalized[$dateCol];
            }
        }

        return $normalized;
    }

    /**
     * Try to parse a date value that might be:
     *  1. An Excel numeric serial  (e.g. 45123)
     *  2. A string in various formats: d/m/Y, d-m-Y, Y-m-d, d.m.Y, etc.
     */
    public function parseDate(mixed $value): ?Carbon
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $str = trim((string) $value);

        // Excel numeric serial date (e.g. 45123)
        if (is_numeric($str) && (int) $str > 1000) {
            try {
                // Excel epoch: 1900-01-01 = serial 1, but has a leap-year bug (+1)
                return Carbon::create(1899, 12, 30)->addDays((int) $str);
            } catch (\Throwable) {
                return null;
            }
        }

        // Try a list of common string formats
        $formats = [
            'Y-m-d',
            'd/m/Y',
            'd-m-Y',
            'm/d/Y',
            'd.m.Y',
            'Y/m/d',
            'd-M-Y',   // 01-Jan-2024
            'd M Y',   // 01 Jan 2024
            'M d, Y',  // Jan 01, 2024
        ];

        foreach ($formats as $fmt) {
            try {
                $dt = Carbon::createFromFormat($fmt, $str);
                if ($dt && $dt->format($fmt) === $str) {
                    return $dt;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        // Last resort: Carbon's own parser
        try {
            return Carbon::parse($str);
        } catch (\Throwable) {
            return null;
        }
    }

    private function err(int $row, string $field, string $message): string
    {
        // return ['row' => $row, 'field' => $field, 'message' => "Row {$row}: {$message}"];
        return "Row {$row}: {$message}";
    }
}