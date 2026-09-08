<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\DebitRequestListRequest;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DebitRequestController extends Controller
{
    /**
     * GET /admin/emi/debit-requests
     *
     * Paginated debit request list for the current month by default.
     *
     * Filters: borrower_id, date, date_from, date_to, status, transaction_id
     *
     * Joins:
     *  enach_debit_requests        → core request data
     *  enach_debit_responses       → Easebuzz API response (pg_transaction_id etc.)
     *  presentment_status_updates  → latest webhook status from Easebuzz/bank
     *  borrower_repayment_schedules→ our internal presentment tracking status
     */
    public function index(DebitRequestListRequest $request): JsonResponse
    {
        $tz      = 'Asia/Kolkata';
        $now     = Carbon::now($tz);
        $perPage = (int) $request->input('per_page', 20);

        // ── Resolve date range (IST) ──────────────────────────────────────────
        [$dateFrom, $dateTo] = $this->resolveDateRange($request, $now, $tz);

        // ── Base query ────────────────────────────────────────────────────────
        $query = DB::table('enach_debit_requests as edr')

            // Response from Easebuzz API
            ->leftJoin('enach_debit_responses as edresp',
                'edresp.merchant_request_number', '=', 'edr.merchant_request_number'
            )

            // Latest webhook status (PRESENTMENT_STATUS_UPDATE) per MRN
            ->leftJoinSub(
                DB::table('presentment_status_updates')
                    ->select([
                        'merchant_request_number',
                        'status',
                        'pg_transaction_id',
                        'transaction_reference_number',
                        'status_at_bank',
                        'bank_reference_number',
                        'amount as webhook_amount',
                        'net_amount',
                        'presentment_date as bank_presentment_date',
                        DB::raw('ROW_NUMBER() OVER (PARTITION BY merchant_request_number ORDER BY id DESC) as rn'),
                    ]),
                'psu',
                fn($join) => $join
                    ->on('psu.merchant_request_number', '=', 'edr.merchant_request_number')
                    ->where('psu.rn', '=', 1)
            )

            // Our internal schedule tracking
            ->leftJoin('borrower_repayment_schedules as brs',
                'brs.merchant_request_number', '=', 'edr.merchant_request_number'
            )

            // ── Columns ───────────────────────────────────────────────────────
            ->select([
                'edr.id                              as debit_request_id',
                'edr.borrower_id',
                'edr.transaction_id                  as enach_transaction_id',
                'edr.merchant_request_number         as mrn',
                'edr.amount                          as requested_amount',
                'edr.presentment_date',
                'edr.created_at                      as requested_at',

                // Easebuzz API response
                'edresp.pg_transaction_id            as easebuzz_pg_transaction_id',
                'edresp.transaction_reference_number',
                'edresp.status                       as api_status',
                'edresp.net_amount                   as api_net_amount',
                'edresp.status_at_bank               as api_status_at_bank',
                'edresp.bank_reference_number        as api_bank_reference_number',

                // Webhook (latest settled status from bank)
                'psu.status                          as webhook_payment_status',
                'psu.status_at_bank                  as webhook_status_at_bank',
                'psu.bank_reference_number           as webhook_bank_reference_number',
                'psu.net_amount                      as webhook_net_amount',

                // Internal tracking
                'brs.id                              as schedule_id',
                'brs.presentment_status              as internal_presentment_status',
                'brs.presentment_attempts',
                'brs.last_presentment_at',
                'brs.emi_amount',
            ])

            // ── Date filter ───────────────────────────────────────────────────
            ->whereBetween('edr.created_at', [
                $dateFrom->toDateTimeString(),
                $dateTo->toDateTimeString(),
            ])

            ->orderBy('edr.created_at', 'desc');

        // ── Optional filters ──────────────────────────────────────────────────

        if ($request->filled('borrower_id')) {
            $query->where('edr.borrower_id', (int) $request->input('borrower_id'));
        }

        if ($request->filled('transaction_id')) {
            // Flexible: match eNACH txn_id, MRN, or Easebuzz pg_transaction_id
            $txn = $request->input('transaction_id');
            $query->where(function ($q) use ($txn) {
                $q->where('edr.transaction_id',          $txn)
                  ->orWhere('edr.merchant_request_number', $txn)
                  ->orWhere('edresp.pg_transaction_id',   $txn)
                  ->orWhere('edresp.transaction_reference_number', $txn);
            });
        }

        if ($request->filled('status')) {
            $statusInput = $request->input('status');
            // Check webhook status first, fall back to API response status
            $query->where(function ($q) use ($statusInput) {
                $q->where('psu.status', $statusInput)
                  ->orWhere(function ($q2) use ($statusInput) {
                      $q2->whereNull('psu.status')
                         ->where('edresp.status', $statusInput);
                  })
                  ->orWhere(function ($q3) use ($statusInput) {
                      $q3->whereNull('psu.status')
                         ->whereNull('edresp.status')
                         ->where('brs.presentment_status', $statusInput);
                  });
            });
        }

        // ── Paginate ──────────────────────────────────────────────────────────
        $paginated = $query->paginate($perPage);

        // ── Format items ──────────────────────────────────────────────────────
        $items = collect($paginated->items())->map(function ($row) use ($tz) {
            // Resolve the most authoritative payment_status
            // Priority: webhook → API response → internal schedule status
            $paymentStatus = $row->webhook_payment_status
                          ?? $row->api_status
                          ?? $row->internal_presentment_status
                          ?? 'initiated';

            return [
                'debit_request_id'             => $row->debit_request_id,
                'borrower_id'                  => $row->borrower_id,
                'enach_transaction_id'         => $row->enach_transaction_id,
                'merchant_request_number'      => $row->mrn,
                'requested_amount'             => $row->requested_amount,
                'emi_amount'                   => $row->emi_amount,
                'presentment_date'             => $row->presentment_date,
                'requested_at_ist'             => $row->requested_at
                    ? Carbon::parse($row->requested_at)->setTimezone($tz)->format('d M Y, h:i A')
                    : null,

                // Easebuzz response data
                'easebuzz_pg_transaction_id'   => $row->easebuzz_pg_transaction_id,
                'transaction_reference_number' => $row->transaction_reference_number,

                // Resolved payment status (most authoritative)
                'payment_status'               => $paymentStatus,
                'status_at_bank'               => $row->webhook_status_at_bank
                                                ?? $row->api_status_at_bank,
                'bank_reference_number'        => $row->webhook_bank_reference_number
                                                ?? $row->api_bank_reference_number,
                'net_amount'                   => $row->webhook_net_amount
                                                ?? $row->api_net_amount,

                // Internal tracking
                'schedule_id'                  => $row->schedule_id,
                'internal_presentment_status'  => $row->internal_presentment_status,
                'presentment_attempts'         => $row->presentment_attempts,
                'last_presentment_at_ist'      => $row->last_presentment_at
                    ? Carbon::parse($row->last_presentment_at)->setTimezone($tz)->format('d M Y, h:i A')
                    : null,
            ];
        });

        return response()->json([
            'status' => 'success',
            'data'   => [
                'items'           => $items,
                'pagination'      => [
                    'total'        => $paginated->total(),
                    'per_page'     => $paginated->perPage(),
                    'current_page' => $paginated->currentPage(),
                    'last_page'    => $paginated->lastPage(),
                    'from'         => $paginated->firstItem(),
                    'to'           => $paginated->lastItem(),
                ],
                'filters_applied' => [
                    'date_from'      => $dateFrom->toDateString(),
                    'date_to'        => $dateTo->toDateString(),
                    'borrower_id'    => $request->input('borrower_id'),
                    'status'         => $request->input('status'),
                    'transaction_id' => $request->input('transaction_id'),
                ],
            ],
        ]);
    }

    /**
     * GET /admin/emi/debit-requests/{id}
     * Full detail for a single debit request including all presentment log entries.
     */
    public function show(int $id): JsonResponse
    {
        $tz = 'Asia/Kolkata';

        $record = DB::table('enach_debit_requests as edr')
            ->leftJoin('enach_debit_responses as edresp',
                'edresp.merchant_request_number', '=', 'edr.merchant_request_number')
            ->leftJoin('borrower_repayment_schedules as brs',
                'brs.merchant_request_number', '=', 'edr.merchant_request_number')
            ->where('edr.id', $id)
            ->select([
                'edr.id as debit_request_id',
                'edr.borrower_id',
                'edr.transaction_id as enach_transaction_id',
                'edr.merchant_request_number as mrn',
                'edr.amount',
                'edr.presentment_date',
                'edr.created_at as requested_at',
                'edr.ip',

                'edresp.pg_transaction_id as easebuzz_pg_transaction_id',
                'edresp.transaction_reference_number',
                'edresp.status as api_status',
                'edresp.net_amount',
                'edresp.status_at_bank',
                'edresp.bank_reference_number',
                'edresp.response_created_at',

                'brs.id as schedule_id',
                'brs.presentment_status as internal_presentment_status',
                'brs.presentment_attempts',
                'brs.last_presentment_at',
                'brs.due_date',
                'brs.emi_amount',
            ])
            ->first();

        if (!$record) {
            return response()->json([
                'status'  => 'failed',
                'message' => 'Debit request not found.',
            ], 404);
        }

        // All webhook updates for this MRN
        $webhookHistory = DB::table('presentment_status_updates')
            ->where('merchant_request_number', $record->mrn)
            ->orderBy('id', 'desc')
            ->get([
                'id', 'status', 'status_at_bank', 'pg_transaction_id',
                'transaction_reference_number', 'bank_reference_number',
                'net_amount', 'amount', 'created_at',
            ])
            ->map(function ($w) use ($tz) {
                $w->created_at_ist = $w->created_at
                    ? Carbon::parse($w->created_at)->setTimezone($tz)->format('d M Y, h:i A')
                    : null;
                return $w;
            });

        // All presentment log entries for this schedule
        $presentmentLogs = $record->schedule_id
            ? DB::table('presentment_logs')
                ->where('schedule_id', $record->schedule_id)
                ->orderBy('id', 'desc')
                ->get()
                ->map(function ($log) use ($tz) {
                    $log->created_at_ist = $log->created_at
                        ? Carbon::parse($log->created_at)->setTimezone($tz)->format('d M Y, h:i A')
                        : null;
                    return $log;
                })
            : collect();

        return response()->json([
            'status' => 'success',
            'data'   => [
                'debit_request'    => $record,
                'webhook_history'  => $webhookHistory,
                'presentment_logs' => $presentmentLogs,
            ],
        ]);
    }

    // ── Private Helpers ───────────────────────────────────────────────────────

    // private function resolveDateRange(
    //     DebitRequestListRequest $request,
    //     Carbon $now,
    //     string $tz
    // ): array {
    //     if ($request->filled('date')) {
    //         $dateFrom = Carbon::parse($request->input('date'), $tz)->startOfDay();
    //         $dateTo   = Carbon::parse($request->input('date'), $tz)->endOfDay();
    //     } elseif ($request->filled('date_from') || $request->filled('date_to')) {
    //         $dateFrom = Carbon::parse(
    //             $request->input('date_from', $now->copy()->startOfMonth()->toDateString()), $tz
    //         )->startOfDay();
    //         $dateTo = Carbon::parse(
    //             $request->input('date_to', $now->copy()->endOfMonth()->toDateString()), $tz
    //         )->endOfDay();
    //     } else {
    //         // Default: current calendar month in IST
    //         $dateFrom = $now->copy()->startOfMonth()->startOfDay();
    //         $dateTo   = $now->copy()->endOfMonth()->endOfDay();
    //     }

    //     return [$dateFrom, $dateTo];
    // }

    private function resolveDateRange(
        DebitRequestListRequest $request,
        Carbon $now,
        string $tz
    ): array {

        // 1. If start_date & end_date are provided
        if ($request->filled('start_date') && $request->filled('end_date')) {
            $dateFrom = Carbon::parse($request->input('start_date'), $tz)->startOfDay();
            $dateTo   = Carbon::parse($request->input('end_date'), $tz)->endOfDay();
        }

        // 2. If month is provided (current year)
        elseif ($request->filled('month')) {
            $monthInput = $request->input('month'); // e.g. "January"

            $monthNumber = Carbon::parse("1 $monthInput")->month;

            $dateFrom = Carbon::create($now->year, $monthNumber, 1, 0, 0, 0, $tz)->startOfMonth();
            $dateTo   = Carbon::create($now->year, $monthNumber, 1, 0, 0, 0, $tz)->endOfMonth();
        }

        // 3. If date is provided
        elseif ($request->filled('date')) {
            $dateFrom = Carbon::parse($request->input('date'), $tz)->startOfDay();
            $dateTo   = Carbon::parse($request->input('date'), $tz)->endOfDay();
        }

        // 2. If date_from and date_to is provided
        elseif ($request->filled('date_from') || $request->filled('date_to')) {
            $dateFrom = Carbon::parse(
                $request->input('date_from', $now->copy()->startOfMonth()->toDateString()), $tz
            )->startOfDay();
            $dateTo = Carbon::parse(
                $request->input('date_to', $now->copy()->endOfMonth()->toDateString()), $tz
            )->endOfDay();
        }

        // 3. Default → current month
        else {
            $dateFrom = $now->copy()->startOfMonth()->startOfDay();
            $dateTo   = $now->copy()->endOfMonth()->endOfDay();
        }

        return [$dateFrom, $dateTo];
    }

    /**
     * GET /admin/emi/debit-requests/export
     *
     * Streams a CSV report of debit requests for a given month.
     * Accepts the same date/filter params as index().
     * Default: current calendar month (IST).
     */
    public function export(DebitRequestListRequest $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $tz  = 'Asia/Kolkata';
        $now = Carbon::now($tz);

        [$dateFrom, $dateTo] = $this->resolveDateRange($request, $now, $tz);

        $query = DB::table('enach_debit_requests as edr')

            ->leftJoin(
                'enach_debit_responses as edresp',
                'edresp.merchant_request_number',
                '=',
                'edr.merchant_request_number'
            )

            ->leftJoinSub(
                DB::table('presentment_status_updates')
                    ->select([
                        'merchant_request_number',
                        'status',
                        'response_description',
                        DB::raw('ROW_NUMBER() OVER (PARTITION BY merchant_request_number ORDER BY id DESC) as rn'),
                    ]),
                'psu',
                fn($join) => $join
                    ->on('psu.merchant_request_number', '=', 'edr.merchant_request_number')
                    ->where('psu.rn', '=', 1)
            )

            ->leftJoin(
                'borrower_repayment_schedules as brs',
                'brs.merchant_request_number',
                '=',
                'edr.merchant_request_number'
            )

            ->leftJoin(
                'borrower_kycs as bk',
                'bk.borrower_id',
                '=',
                'brs.borrower_id'
            )

            // ✅ Correct join order (after edr) and correct condition
            ->leftJoin(
                'loan_basic_details as lbd',
                'lbd.id',
                '=',
                'edr.borrower_id'
            )

            ->select([
                'edresp.pg_transaction_id              as easebuzz_id',  // ✅ edr.transaction_id as agreed
                'edr.amount                      as requested_amount',
                'edr.created_at                  as requested_at',
                'bk.name                         as customer_name',
                'bk.mobile                       as customer_phone',
                'lbd.borrower_id                 as borrower_id',  // ✅ from loan_basic_details
                DB::raw("COALESCE(psu.status, edresp.status, brs.presentment_status, 'initiated') as payment_status"),
                DB::raw("CASE
                            WHEN COALESCE(psu.status, edresp.status, brs.presentment_status) = 'success'
                                THEN 'Successful Transaction'
                            ELSE COALESCE(psu.response_description, '')
                         END as error_message"),
            ])

            ->whereBetween('edr.created_at', [
                $dateFrom->toDateTimeString(),
                $dateTo->toDateTimeString(),
            ])

            ->orderBy('edr.created_at', 'desc');

        // ── Optional filters ──────────────────────────────────────────────────────

        if ($request->filled('borrower_id')) {
            $query->where('edr.borrower_id', (int) $request->input('borrower_id'));
        }

        if ($request->filled('status')) {
            $statusInput = $request->input('status');
            $query->where(function ($q) use ($statusInput) {
                $q->where('psu.status', $statusInput)
                  ->orWhere(function ($q2) use ($statusInput) {
                      $q2->whereNull('psu.status')
                         ->where('edresp.status', $statusInput);
                  })
                  ->orWhere(function ($q3) use ($statusInput) {
                      $q3->whereNull('psu.status')
                         ->whereNull('edresp.status')
                         ->where('brs.presentment_status', $statusInput);
                  });
            });
        }

        if ($request->filled('transaction_id')) {
            $txn = $request->input('transaction_id');
            $query->where(function ($q) use ($txn) {
                $q->where('edr.transaction_id', $txn)
                  ->orWhere('edr.merchant_request_number', $txn)
                  ->orWhere('edresp.pg_transaction_id', $txn)
                  ->orWhere('edresp.transaction_reference_number', $txn);
            });
        }

        // ── Filename ──────────────────────────────────────────────────────────────
        $filename = 'payment_report_'
            . $dateFrom->format('Y-m-d')
            . '_to_'
            . $dateTo->format('Y-m-d')
            . '.csv';

        // ── Stream CSV ────────────────────────────────────────────────────────────
        return response()->streamDownload(function () use ($query, $tz) {

            $handle = fopen('php://output', 'w');

            fwrite($handle, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel

            fputcsv($handle, [
                'Easebuzz ID',
                'Amount',
                'Transaction Status',
                'Customer Name',
                'Borrower ID',
                'Error Message',
                'Customer Phone',
                'Date of Transaction',
            ]);

            $query->chunk(500, function ($rows) use ($handle, $tz) {
                foreach ($rows as $row) {
                    fputcsv($handle, [
                        $row->easebuzz_id,
                        $row->requested_amount,
                        $row->payment_status,
                        $row->customer_name    ?? '',
                        $row->borrower_id      ?? '',
                        $row->error_message,
                        $row->customer_phone   ?? '',
                        $row->requested_at
                            ? Carbon::parse($row->requested_at)
                                    ->setTimezone($tz)
                                    ->format('d M Y, h:i A')
                            : '',
                    ]);
                }
            });

            fclose($handle);

        }, $filename, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control'       => 'no-store',
            'Pragma'              => 'no-cache',
        ]);
    }

    /**
     * GET /admin/emi/debit-requests/export-failed-presentments
     *
     * Exports presentment_logs where status = 'failed' for the current month (default).
     * Accepts same date params: date, date_from, date_to.
     */
    public function exportFailedPresentments(DebitRequestListRequest $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $tz  = 'Asia/Kolkata';
        $now = Carbon::now($tz);

        [$dateFrom, $dateTo] = $this->resolveDateRange($request, $now, $tz);

        $query = DB::table('presentment_logs as pl')

            ->leftJoin(
                'borrower_repayment_schedules as brs',
                'brs.id',
                '=',
                'pl.schedule_id'
            )

            ->leftJoin(
                'borrower_kycs as bk',
                'bk.borrower_id',
                '=',
                'brs.borrower_id'
            )

            ->leftJoin(
                'loan_basic_details as lbd',
                'lbd.id',
                '=',
                'brs.borrower_id'
            )

            ->select([
                'lbd.borrower_id                as borrower_id',
                'bk.name                        as customer_name',
                'bk.mobile                      as customer_phone',
                'pl.status',
                'pl.error_message',
                'pl.attempt',
                'pl.merchant_request_number',
                'pl.created_at                  as log_created_at',
            ])

            ->where('pl.status', 'failed')

            ->whereBetween('pl.created_at', [
                $dateFrom->toDateTimeString(),
                $dateTo->toDateTimeString(),
            ])

            ->orderBy('pl.created_at', 'desc');

        // ── Optional filters ──────────────────────────────────────────────────────

        if ($request->filled('borrower_id')) {
            $query->where('brs.borrower_id', (int) $request->input('borrower_id'));
        }

        // ── Filename ──────────────────────────────────────────────────────────────
        $filename = 'failed_presentments_'
            . $dateFrom->format('Y-m-d')
            . '_to_'
            . $dateTo->format('Y-m-d')
            . '.csv';

        // ── Stream CSV ────────────────────────────────────────────────────────────
        return response()->streamDownload(function () use ($query, $tz) {

            $handle = fopen('php://output', 'w');

            fwrite($handle, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel

            fputcsv($handle, [
                'Borrower ID',
                'Customer Name',
                'Customer Phone',
                'Status',
                'Error Message',
                'Attempt #',
                // 'Merchant Request Number',
                'Date',
            ]);

            $query->chunk(500, function ($rows) use ($handle, $tz) {
                foreach ($rows as $row) {
                    fputcsv($handle, [
                        $row->borrower_id             ?? '',
                        $row->customer_name           ?? '',
                        $row->customer_phone          ?? '',
                        $row->status,
                        $row->error_message           ?? '',
                        $row->attempt,
                        // $row->merchant_request_number ?? '',
                        $row->log_created_at
                            ? Carbon::parse($row->log_created_at)
                                    ->setTimezone($tz)
                                    ->format('d M Y, h:i A')
                            : '',
                    ]);
                }
            });

            fclose($handle);

        }, $filename, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control'       => 'no-store',
            'Pragma'              => 'no-cache',
        ]);
    }
}