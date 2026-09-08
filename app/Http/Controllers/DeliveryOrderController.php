<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DeliveryOrder;
use App\Models\LenderBorrowerGroup;
use App\Models\Lenders;
use App\Models\LoanBasicDetails;
use App\Models\ProductModel;
use App\Traits\ApiResponse;
use App\Traits\CalculatesLoanSchedule;
use App\Traits\SendsDeliveryOrderMail;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DeliveryOrderController extends Controller
{
    use ApiResponse;
    use CalculatesLoanSchedule;
    use SendsDeliveryOrderMail;

    private const PDF_DISK        = 'public';
    private const PDF_PATH        = 'delivery-orders';
    private const GST_RATE        = 18;
    private const FILE_CHARGE_PCT = 1;

    // -------------------------------------------------- endpoints

    public function getDetails(Request $request): JsonResponse
    {
        $request->validate([
            'borrower_id' => ['required', 'integer', 'exists:loan_basic_details,id'],
        ]);

        $loan = $this->resolveBorrower($request->integer('borrower_id'));

        if (! $loan) {
            return $this->send_response('failed', 'No loan record found for this Borrower ID.', 422);
        }

        return $this->send_response('success', 'Details fetched.', 200, $this->mapDetails($loan));
    }

    public function generate(Request $request): JsonResponse
    {
        $data = $this->validatePayload($request);

        $loan = $this->resolveBorrower($request->integer('borrower_id'));

        if (! $loan) {
            return $this->send_response('failed', 'No loan record found for this Borrower ID.', 422);
        }

        $do = DeliveryOrder::create($data + [
            'lender_id'      => $this->resolveLender($loan)?->id,
            'borrower_id'    => $loan->id,               // FK to loan (numeric PK)
            'application_no' => $loan->borrower_id,       // FIN-2454, for display
            'dealer_id'      => $loan->dealer?->id,
            'do_number'      => $this->nextDoNumber(),
            'status'         => 'generated',
            'payload'        => $data,
            'generated_by'   => $request->user()?->id,
            'ip'             => $request->ip(),
        ]);

        $do->update([
            'file_path'    => $this->renderPdf($do, $loan),
            'access_token' => $do->access_token ?: Str::random(48),
        ]);

        return $this->send_response('success', 'Delivery Order generated.', 200, [
            'do_id'          => $do->id,
            'do_number'      => $do->do_number,
            'application_no' => $do->application_no,
            // 'file_url'       => route('delivery-order.preview', ['do' => $do->uuid]),
            'file_url'       => url("api/admin/delivery-order/preview/{$do->uuid}"),
        ]);
    }

    /** Streams the PDF bytes. React fetches this as a blob (bearer auth). Bound by uuid. */
    public function preview(DeliveryOrder $do): StreamedResponse|JsonResponse
    {
        if (! $do->file_path || ! Storage::disk(self::PDF_DISK)->exists($do->file_path)) {
            return $this->send_response('failed', 'Delivery Order file not found.', 422);
        }

        return Storage::disk(self::PDF_DISK)->response(
            $do->file_path,
            null,
            ['Content-Type' => 'application/pdf']
        );
    }

    public function send(Request $request): JsonResponse
    {
        $request->validate([
            'do_id' => ['required', 'integer', 'exists:delivery_orders,id'],
            'cc'    => ['nullable'],
            'bcc'   => ['nullable'],
        ]);

        $do = DeliveryOrder::findOrFail($request->integer('do_id'));

        if (! $do->file_path || ! Storage::disk(self::PDF_DISK)->exists($do->file_path)) {
            return $this->send_response('failed', 'Delivery Order file is missing. Regenerate before sending.', 422);
        }

        $result = $this->sendDeliveryOrderMail($do, [
            'cc'  => $request->input('cc', []),
            'bcc' => $request->input('bcc', 'credit.ops@finayo.tech'),
        ]);

        $sent = collect($result)->filter()->isNotEmpty();

        $do->update([
            'status'        => $sent ? 'sent' : 'failed',
            'sent_at'       => $sent ? now() : $do->sent_at,
            'mail_response' => collect($result)->map(fn ($log) => $log?->id)->all(),
        ]);

        if (! $sent) {
            return $this->send_response('failed', 'Mail dispatch failed. Check logs and retry.', 500);
        }

        return $this->send_response('success', 'Delivery Order sent successfully.', 200);
    }

    /** Public — authorised by the unguessable access_token in the email link. */
    public function download(string $token): StreamedResponse
    {
        $do = DeliveryOrder::where('access_token', $token)->firstOrFail();

        abort_unless($do->file_path && Storage::disk(self::PDF_DISK)->exists($do->file_path), 404);

        return Storage::disk(self::PDF_DISK)->response(
            $do->file_path,
            'delivery-order-' . ($do->application_no ?: $do->id) . '.pdf',
            ['Content-Type' => 'application/pdf']
        );
    }

    // -------------------------------------------------- resolvers

    /** Request always sends the numeric loan PK. */
    protected function resolveBorrower(int $id): ?LoanBasicDetails
    {
        return LoanBasicDetails::query()
            ->with(['dealer', 'latestBorrowerKyc', 'latestLoanScheme'])
            ->find($id);
    }

    protected function resolveLender(LoanBasicDetails $loan): ?Lenders
    {
        if (! empty($loan->lender_id)) {
            $lender = Lenders::find($loan->lender_id);

            if ($lender) {
                return $lender;
            }
        }

        $group = LenderBorrowerGroup::where('borrower_id', $loan->borrower_id)->first();

        return $group ? Lenders::find($group->lender_id) : null;
    }

    // -------------------------------------------------- data mapping

    protected function mapDetails(LoanBasicDetails $loan): array
    {
        $scheme = $loan->latestLoanScheme;

        $interestRate        = (float) ($scheme->interest ?? 0);
        $roiType             = $this->normalizeRoiType($scheme->interest_type ?? 'flat');
        $interestType        = $scheme->interest_type ?? $roiType;
        $iotAmount           = (float) ($scheme->iot ?? 0);
        $tenure              = (int) $loan->loan_term;
        $secondYearInsurance = ($tenure > 15 && $tenure <= 24)
            ? (float) ($scheme->insurance_2nd_year ?? 0)
            : 0.0;

        $finalLoanAmount = (float) $loan->loan_amount + $iotAmount + $secondYearInsurance;
        $lender          = $this->resolveLender($loan);

        return [
            'loan_id'          => $loan->id,              // numeric PK — send this back in generate request
            'application_no'   => $loan->borrower_id,     // FIN-2454 — display everywhere
            'borrower_name'    => $loan->latestBorrowerKyc->name ?? '',
            'borrower_email'   => $loan->latestBorrowerKyc->email ?? '',
            'lender_id'        => $lender->id ?? null,
            'lender_name'      => $lender->name ?? '',
            'lender_email'     => $lender->email ?? '',
            'dealer_name'      => $loan->dealer->name ?? '',
            'dealer_email'     => $loan->dealer->email ?? '',
            'do_date'          => now()->toDateString(),
            'ex_showroom_cost' => (float) ($scheme->ex_showroom_price ?? 0),
            'loan_amount'      => round($finalLoanAmount, 2),
            'down_payment'     => (float) ($loan->down_payment ?? 0),
            'roi'              => $interestRate,
            'roi_type'         => $roiType,
            'tenure'           => $tenure,
            'emi'              => $this->calculateEmi($finalLoanAmount, $interestRate, $tenure, $roiType, $interestType),
            'file_charge'      => $this->fileCharge($finalLoanAmount),
        ];
    }

    protected function normalizeRoiType(mixed $interestType): string
    {
        $value = strtolower(trim((string) $interestType));

        return in_array($value, ['flat', '2', 'flat_rate'], true) ? 'flat' : 'reducing';
    }

    protected function calculateEmi(float $principal, float $rate, int $tenure, string $roiType, string $interestType): float
    {
        if ($principal <= 0 || $tenure <= 0) {
            return 0.0;
        }

        $schedule = $this->generateInterestSchedule($principal, $rate, $tenure, $interestType);

        return round((float) ($schedule['emi_amount'] ?? 0), 2);
    }

    protected function fileCharge(float $loanAmount): float
    {
        $base = $loanAmount * (self::FILE_CHARGE_PCT / 100);

        return round($base * (1 + self::GST_RATE / 100), 2);
    }

    protected function validatePayload(Request $request): array
    {
        return $request->validate([
            'borrower_id'         => ['required', 'integer', 'exists:loan_basic_details,id'],
            'do_date'             => ['required', 'date'],
            'ex_showroom_cost'    => ['required', 'numeric', 'min:0'],
            'loan_amount'         => ['required', 'numeric', 'min:0'],
            'down_payment'        => ['required', 'numeric', 'min:0'],
            'roi'                 => ['required', 'numeric', 'min:0', 'max:100'],
            'roi_type'            => ['required', 'in:reducing,flat'],
            'tenure'              => ['required', 'integer', 'min:1', 'max:120'],
            'emi'                 => ['required', 'numeric', 'min:0'],
            'file_charge'         => ['required', 'numeric', 'min:0'],
            'disbursement_amount' => ['required', 'numeric', 'min:1'],
            'borrower_name'       => ['nullable', 'string', 'max:150'],
            'borrower_email'      => ['required', 'email'],
            'dealer_name'         => ['nullable', 'string', 'max:150'],
            'dealer_email'        => ['required', 'email'],
            'lender_name'         => ['nullable', 'string', 'max:150'],
        ]);
    }

    // -------------------------------------------------- pdf

    protected function nextDoNumber(): string
    {
        $seq = DeliveryOrder::whereYear('created_at', now()->year)->count() + 1;

        return 'DO/' . now()->format('Ym') . '/' . str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
    }

    protected function embedImage(?string $relativePath): ?string
    {
        if (! $relativePath) {
            return null;
        }

        $full = public_path($relativePath);

        if (! is_file($full) || ! is_readable($full)) {
            return null;
        }

        $mime = function_exists('mime_content_type')
            ? (mime_content_type($full) ?: 'image/png')
            : 'image/png';

        return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($full));
    }

    protected function renderPdf(DeliveryOrder $do, LoanBasicDetails $loan): string
    {
        $file = self::PDF_PATH . '/' . Str::slug($do->do_number) . '-' . $do->id . '.pdf';

        $scheme        = $loan->latestLoanScheme;
        $product       = $scheme?->product_id ? ProductModel::find($scheme->product_id) : null;
        $lenderDetails = $this->lenderDetails($this->resolveLender($loan));

        $pdf = Pdf::loadView('delivery-order.pdf', [
            'do'             => $do,
            'loan'           => $loan,
            'application_no' => $do->application_no,
            'lender_details' => $lenderDetails,
            'product'        => $product,
            'dealer'         => $loan->dealer,
            'dealer_address' => $this->dealerAddress($loan->dealer),
            'lender_logo'    => $this->embedImage($lenderDetails['logo_path'] ?? null),
            'finayo_logo'    => $this->embedImage('Assets/pdf-image/finayo-logo-pdf.png'),
        ])->setPaper('a4')->setOptions([
            'isHtml5ParserEnabled' => true,
            'isRemoteEnabled'      => false,
        ]);

        Storage::disk(self::PDF_DISK)->put($file, $pdf->output());

        return $file;
    }

    protected function lenderDetails(?Lenders $lender): array
    {
        $id = $lender->id ?? null;

        if ($id == 3) {
            return [
                'company_name'  => 'Akme Fintrade India Ltd (AFIL)',
                'company_short' => 'AKME',
                'legal_name'    => 'AKME FINTRADE INDIA LTD.',
                'address'       => 'Akme Business Centre, 4-5 Subcity Center, Savina Circle, Udaipur 313002, Rajasthan',
                'logo_path'     => 'Assets/pdf-image/akme-logo-pdf.jpg',
                'logo_height'   => 50,
            ];
        }

        if ($id == 1) {
            return [
                'company_name'  => 'F MEC International Financial Services Ltd',
                'company_short' => 'F MEC',
                'legal_name'    => 'F MEC INTERNATIONAL FINANCIAL SERVICES LTD.',
                'address'       => '908, 9th Floor Mercantile House, 15 K.G. Marg New Delhi – 110 001',
                'logo_path'     => 'Assets/pdf-image/fmec-international.png',
                'logo_height'   => 70,
            ];
        }

        return [
            'company_name'  => $lender->name ?? '',
            'company_short' => '',
            'legal_name'    => strtoupper($lender->name ?? ''),
            'address'       => $lender->address ?? '',
            'logo_path'     => null,
            'logo_height'   => 50,
        ];
    }

    protected function dealerAddress(mixed $dealer): string
    {
        if (! $dealer) {
            return '';
        }

        $parts = array_filter([
            $dealer->address_line_1 ?? null,
            $dealer->address_line_2 ?? null,
            $dealer->city ?? null,
            $dealer->pincode ?? null,
        ], fn ($v) => trim((string) $v) !== '');

        return implode(', ', $parts);
    }
}