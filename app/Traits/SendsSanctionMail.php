<?php

namespace App\Traits;

use App\Models\LoanBasicDetails;
use App\Models\LenderBorrowerGroup;
use App\Models\TeleduceMailLog;
use App\Services\Teleduce\TeleduceMail;
use App\Services\Teleduce\TeleduceRecipient;
use Illuminate\Support\Facades\Log;

/**
 * SendsSanctionMail
 *
 * Drop this trait into any class (controller, job, command, service) to
 * send a sanction-letter email with a single call:
 *
 *   $this->sendSanctionMail($loanId);
 *
 * Both the dealer and the borrower receive personalised copies.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * PRODUCT IDs
 *   1, 2, 3 → E-Rickshaw / Vehicle documents
 *   4       → Battery documents
 * ─────────────────────────────────────────────────────────────────────────
 */
trait SendsSanctionMail
{
    // ──────────────────────────────────────────────────────────────────────
    // Public entry-point
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Trigger sanction-letter emails for a loan.
     *
     * @param  int|string  $loanId   LoanBasicDetails primary key
     * @return array{dealer: TeleduceMailLog|null, borrower: TeleduceMailLog|null}
     */
    public function sendSanctionMail(int|string $loanId): array
    {
        $loan = $this->resolveLoan($loanId);

        $borrowerName  = $loan->latestBorrowerKyc?->name ?? 'Borrower';
        $borrowerEmail = $loan->user_detail?->email;
        $dealerName    = $loan->dealer_detail?->name ?? 'Dealer';
        $dealerEmail   = $loan->dealer_detail?->email;

        $sharedData = $this->buildSharedData($loan);

        $results = [
            'dealer'   => null,
            'borrower' => null,
        ];

        // ── Mail to Dealer ────────────────────────────────────────────────
        if ($dealerEmail) {
            $results['dealer'] = $this->dispatchMail(
                recipientEmail: $dealerEmail,
                recipientName:  $dealerName,
                subject:        "Loan Sanctioned — {$borrowerName} (ID: {$loan->borrower_id})",
                data: array_merge($sharedData, [
                    'dealer_name'    => $dealerName,
                    'borrower_name'  => $borrowerName,
                    'recipient_type' => 'dealer',
                ]),
                reference: "sanction-dealer-{$loan->borrower_id}",
            );
        }

        // ── Mail to Borrower ──────────────────────────────────────────────
        if ($borrowerEmail) {
            $results['borrower'] = $this->dispatchMail(
                recipientEmail: $borrowerEmail,
                recipientName:  $borrowerName,
                subject:        "Your Loan Has Been Sanctioned (ID: {$loan->borrower_id})",
                data: array_merge($sharedData, [
                    'dealer_name'    => $dealerName,
                    'borrower_name'  => $borrowerName,
                    'recipient_type' => 'borrower',
                ]),
                reference: "sanction-borrower-{$loan->borrower_id}",
            );
        }

        return $results;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Model resolver
    // ──────────────────────────────────────────────────────────────────────

    protected function resolveLoan(int|string $loanId): LoanBasicDetails
    {
        return LoanBasicDetails::with([
            'user_detail',            // borrower email
            'dealer_detail',          // dealer name + email
            'latestBorrowerKyc',      // borrower name  → ->name
            'latestCoapplicantKyc',   // co-applicant presence check
            'latestGuarantor',        // guarantor presence check
            'latestLoanScheme',       // for net loan amount (iot)
            'lenderGroup',            // lender fallback via pivot
        ])->findOrFail($loanId);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Shared view-data builder
    // ──────────────────────────────────────────────────────────────────────

    protected function buildSharedData(LoanBasicDetails $loan): array
    {
        return [
            'loan_id'        => $loan->borrower_id,
            'loan_amount'    => $this->calculateNetLoanAmount(
                                    $loan->loan_amount,
                                    $loan->loan_term,
                                    $loan->latestLoanScheme?->iot ?? 0,
                                ),
            'product_id'     => $loan->product_id,
            'lender_details' => $this->resolveLenderDetails($loan),
            'documents'      => $this->buildDocumentList($loan),
        ];
    }

    // ──────────────────────────────────────────────────────────────────────
    // Net loan amount  (mirrors your existing calculation)
    // ──────────────────────────────────────────────────────────────────────

    protected function calculateNetLoanAmount(
        float|int $loanAmount,
        int       $loanTerm,
        float|int $iotAmount = 0,
    ): float|int {
        $secondYearInsurance = ($loanTerm > 15 && $loanTerm <= 24) ? 0 : 0;

        return $loanAmount + $iotAmount + $secondYearInsurance;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Lender details
    // ──────────────────────────────────────────────────────────────────────

    protected function resolveLenderDetails(LoanBasicDetails $loan): array
    {
        // 1. Direct column on the loan
        $lenderId = $loan->lender_id ?? null;

        // 2. Fallback to LenderBorrowerGroup pivot (already eager-loaded)
        if (! $lenderId) {
            $lenderId = $loan->lenderGroup?->lender_id;
        }

        return match ((int) $lenderId) {
            3 => [
                'company_name'      => 'Akme Fintrade India Ltd (AFIL)',
                'company_short'     => 'AKME',
                'address'           => 'Akme Business Centre, 4-5 Subcity Center, Savina Circle, Udaipur 313002, Rajasthan',
                'corporate_address' => 'Office No. D-4, Neelkanth Business Park, Nathani Road, Vidhyavihar (West) Mumbai-400086',
                'approach_via_mail' => 'https://www.akmefintrade.com/grievance-redressal-mechanism/',
            ],
            1 => [
                'company_name'      => 'F MEC International Financial Services Ltd',
                'company_short'     => 'F MEC',
                'address'           => '908, 9th Floor Mercantile House, 15 K.G. Marg New Delhi – 110 001',
                'corporate_address' => '908, 9th Floor Mercantile House, 15 K.G. Marg New Delhi – 110 001',
                'approach_via_mail' => '',
            ],
            default => [
                'company_name'      => '',
                'company_short'     => '',
                'address'           => '',
                'corporate_address' => '',
                'approach_via_mail' => '',
            ],
        };
    }

    // ──────────────────────────────────────────────────────────────────────
    // Document list builder
    // ──────────────────────────────────────────────────────────────────────

    protected function buildDocumentList(LoanBasicDetails $loan): array
    {
        $docs    = [];
        $kycBase = ['Aadhaar Card', 'PAN Card'];

        // Applicant — always present
        $docs['kyc']['applicant'] = $kycBase;

        // Co-applicant — check via latestCoapplicantKyc relation
        $docs['kyc']['co_applicant'] = $loan->latestCoapplicantKyc
            ? $kycBase
            : [];

        // Guarantor — check via latestGuarantor relation
        $docs['kyc']['guarantor'] = $loan->latestGuarantor
            ? $kycBase
            : [];

        // Driving licence is always optional
        $docs['kyc']['optional'] = ['Driving Licence'];

        // Product-specific docs
        $docs['product'] = match ((int) $loan->product_id) {
            1, 2, 3 => $this->vehicleDocuments(),
            4       => $this->batteryDocuments(),
            default => [],
        };

        return $docs;
    }

    protected function vehicleDocuments(): array
    {
        return [
            'Vehicle RTO Certificate',
            'Vehicle RTO Tax Receipt',
            "Vehicle MMR (Manufacturer's Certificate)",
            'Vehicle Invoice',
            'Vehicle Insurance',
            'Vehicle Image',
            'Chassis Image',
            'Motor Controller Image',
            'Battery Image',
            'Registration Certificate (RC)',
        ];
    }

    protected function batteryDocuments(): array
    {
        return [
            'Battery Invoice',
            'Battery Warranty Document',
            'Battery Bank Statement',
            'NOC Document',
        ];
    }

    // ──────────────────────────────────────────────────────────────────────
    // Mail dispatcher
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Dispatch a single mail. Failures are caught individually so one
     * failure never blocks the other recipient from receiving their copy.
     */
    protected function dispatchMail(
        string $recipientEmail,
        string $recipientName,
        string $subject,
        array  $data,
        string $reference,
    ): ?TeleduceMailLog {
        try {
            return TeleduceMail::send(
                view:       'emails.sanction_letter',
                data:       $data,
                subject:    $subject,
                recipients: [
                    TeleduceRecipient::make($recipientEmail, $recipientName),
                ],
                options: ['reference' => $reference],
            );
        } catch (\Throwable $e) {
            Log::error('[SendsSanctionMail] Failed to send sanction mail', [
                'recipient' => $recipientEmail,
                'reference' => $reference,
                'error'     => $e->getMessage(),
            ]);

            return null;
        }
    }
}