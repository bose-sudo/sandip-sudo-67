<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Dealer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\{Font, Fill, Alignment, Border};
use Symfony\Component\HttpFoundation\StreamedResponse;

class DealerReportController extends Controller
{
    private array $columns = [
        'Dealer Code',
        'Dealer Name',
        'Mobile Number',
        'Email ID',
        'Address',
        'Location',
        'State',
        'Status',
        'No Of Disbursed Applications',
        'OEM',
        'Authorized Person(s)',
    ];

    /**
     * GET /api/reports/dealers
     * Returns paginated JSON for listing.
     */
    public function index(Request $request)
    {
        $query = $this->buildQuery();

        $perPage = min((int) $request->get('per_page', 15), 100);
        $data    = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => $data,
        ]);
    }

    /**
     * GET /api/reports/dealers/export
     * Streams an .xlsx file download.
     */
    public function export(Request $request): StreamedResponse
    {
        $dealers = $this->buildQuery()->get();

        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Dealer Report');

        // ── Header row ────────────────────────────────────────────────
        $sheet->fromArray($this->columns, null, 'A1');

        $headerStyle = [
            'font'      => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF'], 'size' => 11],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1F3A5F']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFBFBFBF']]],
        ];

        $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($this->columns));
        $sheet->getStyle("A1:{$lastCol}1")->applyFromArray($headerStyle);
        $sheet->getRowDimension(1)->setRowHeight(22);

        // ── Data rows ─────────────────────────────────────────────────
        $rowIndex = 2;
        foreach ($dealers as $dealer) {
            $address = collect([
                $dealer->address_line_1,
                $dealer->address_line_2,
                $dealer->landmark,
            ])->filter()->implode(', ');

            $sheet->fromArray([
                $dealer->dealer_unq,
                $dealer->name,
                $dealer->mobile,
                $dealer->email,
                $address,
                $dealer->city,
                $dealer->state_name      ?? '',
                $dealer->is_active ? 'Active' : 'Inactive',
                $dealer->disbursed_count ?? 0,   // FIX #7: matches subquery alias
                $dealer->oem_name        ?? '',
                $dealer->authorized_persons ?? '',
            ], null, "A{$rowIndex}");

            // Alternate row shading
            if ($rowIndex % 2 === 0) {
                $sheet->getStyle("A{$rowIndex}:{$lastCol}{$rowIndex}")
                      ->getFill()
                      ->setFillType(Fill::FILL_SOLID)
                      ->getStartColor()->setARGB('FFF4F6F9');
            }

            $rowIndex++;
        }

        // ── Column widths ─────────────────────────────────────────────
        $widths = [16, 24, 16, 28, 36, 16, 16, 12, 24, 32, 40];
        foreach ($widths as $i => $w) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
            $sheet->getColumnDimension($col)->setWidth($w);
        }

        // ── Stream response ───────────────────────────────────────────
        $filename = 'dealer_report_' . now()->format('Ymd_His') . '.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, $filename, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control'       => 'max-age=0',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // Shared query builder
    // ─────────────────────────────────────────────────────────────────
    private function buildQuery()
    {
        return Dealer::query()
            ->select([
                'dealers.id',
                'dealers.dealer_unq',
                'dealers.name',
                'dealers.mobile',
                'dealers.email',
                'dealers.address_line_1',
                'dealers.address_line_2',
                'dealers.landmark',
                'dealers.city',
                'dealers.is_active',

                // State name via join
                DB::raw('sm.state AS state_name'),

                // OEM name via join
                DB::raw('mfr.manufacturer AS oem_name'),

                // FIX #2: correlated subquery replaces withCount() so it plays
                // nicely with the manual groupBy without breaking strict mode.
                // FIX #7: alias is 'disbursed_count' — update references above.
                DB::raw("(
                    SELECT COUNT(*)
                    FROM loan_basic_details
                    WHERE loan_basic_details.dealer_id = dealers.dealer_unq
                      AND loan_basic_details.status    = 'DISBURSED'
                      AND loan_basic_details.deleted_at IS NULL
                ) AS disbursed_count"),

                // PostgreSQL: array_agg(DISTINCT ...) then join — the only way to
                // get both DISTINCT and a clean separator in PG.
                DB::raw(
                    "ARRAY_TO_STRING(
                        ARRAY_AGG(DISTINCT dda.name ORDER BY dda.name),
                        ', '
                    ) AS authorized_persons"
                ),
            ])
            ->leftJoin('state_masters as sm',  'sm.id',  '=', 'dealers.state')
            ->leftJoin('manufacturers as mfr', 'mfr.id', '=', 'dealers.oem_id')
            ->leftJoin('dealer_designated_authorities as dda', function ($join) {
                $join->on('dda.dealer_id', '=', 'dealers.id');
            })

            ->where('dealers.is_deleted', false)

            ->groupBy(
                'dealers.id',
                'dealers.dealer_unq',
                'dealers.name',
                'dealers.mobile',
                'dealers.email',
                'dealers.address_line_1',
                'dealers.address_line_2',
                'dealers.landmark',
                'dealers.city',
                'dealers.is_active',
                'sm.state',
                'mfr.manufacturer',
            )
            ->orderBy('dealers.id');
    }
}