<?php

namespace App\Services\Graviton;

use App\Models\BorrowerKyc;
use Illuminate\Support\Facades\Log;

class GravitonDedupeService
{
    public function __construct(
        private GravitonAuthService $auth
    ) {}

    /**
     * Check duplicate for a single borrower and save result
     */
    public function checkForBorrower(BorrowerKyc $borrower): array
    {
        // Validate required data
        if (empty($borrower->mobile)) {
            throw new \Exception("Borrower {$borrower->id} has no mobile number");
        }

        if (empty($borrower->pan)) {
            throw new \Exception("Borrower {$borrower->id} has no PAN");
        }

        $payload = [
            'mobileNumber' => $borrower->mobile,
            'kyc' => [
                [
                    'documentKey'  => $borrower->pan,
                    'documentType' => 2,   // Static: 2 = PAN
                ],
            ],
        ];

        Log::info("Dedupe API call", [
            'borrower_id' => $borrower->id,
            'payload'     => $payload,
        ]);

        try {
            $response = $this->auth->client()->post('/clients/dedupe', $payload);

            if (!$response->successful()) {
                throw new \Exception('Dedupe API failed: HTTP ' . $response->status() . ' — ' . $response->body());
            }

            $data = $response->json();

            $matches        = $data['dedupeData'] ?? [];
            $matchCount     = count($matches);
            $overallMatch   = (float) ($data['overAllMatchPercentage'] ?? 0);

            // ─────────────────────────────────────────
            // Save result to borrower_kycs
            // ─────────────────────────────────────────
            $borrower->dedupe_match_percentage = $overallMatch;
            $borrower->dedupe_data             = $data;
            $borrower->dedupe_checked_at       = now();

            if ($matchCount > 0) {
                // ✅ Duplicate found → APPROVE না
                $borrower->dedupe_status      = 'matched';
                $borrower->graviton_client_id = $matches[0]['clientId'] ?? null;
            } else {
                // ✅ No duplicate → APPROVE
                $borrower->dedupe_status = 'no_match';
                $borrower->graviton_client_id = null;
            }

            $borrower->save();

            Log::info("Dedupe check completed", [
                'borrower_id'   => $borrower->id,
                'status'        => $borrower->dedupe_status,
                'match_count'   => $matchCount,
                'overall_match' => $overallMatch,
            ]);

            return [
                'borrower_id'   => $borrower->id,
                'status'        => $borrower->dedupe_status,
                'match_count'   => $matchCount,
                'overall_match' => $overallMatch,
                'matches'       => $matches,
                'is_approved'   => $matchCount === 0,
            ];
        } catch (\Throwable $e) {
            $borrower->dedupe_status = 'failed';
            $borrower->save();

            Log::error("Dedupe check FAILED", [
                'borrower_id' => $borrower->id,
                'error'       => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}