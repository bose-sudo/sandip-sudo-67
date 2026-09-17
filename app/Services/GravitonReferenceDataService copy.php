<?php

namespace App\Services;

use App\Models\BorrowerKyc;
use App\Models\GravitonReferenceData;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GravitonReferenceDataService
{
    protected string $baseUrl;
    protected string $tenantId;
    protected ?string $accessToken = null;

    public function __construct()
    {
        $this->baseUrl  = rtrim(config('graviton.base_url'), '/');
        $this->tenantId = config('graviton.tenant_id');
    }

    /**
     * Main entry point — sync reference data for a single borrower.
     */
    public function syncForBorrower(BorrowerKyc $borrower): GravitonReferenceData
    {
        $record = GravitonReferenceData::firstOrNew(['borrower_id' => $borrower->id]);
        $record->sync_status = 'pending';
        $record->save();

        try {
            // 1. Authenticate
            $this->authenticate();

            // 2. Resolve productId & clientId (null-safe)
            $productId = $borrower->loanScheme?->product_id ?? null;
            $clientId  = $borrower->borrower_id;

            // 3. Call Loan Template API
            $loanTemplate = $this->fetchLoanTemplate($productId, $clientId);
            $record->loan_template_data = $loanTemplate;

            // 4. Call Family Member Template API
            $familyTemplate = $this->fetchFamilyMemberTemplate($clientId ?? 0);
            $record->family_member_template_data = $familyTemplate;

            // 5. Call Credit Bureau Template API
            $creditTemplate = $this->fetchCreditBureauTemplate();
            $record->credit_bureau_template_data = $creditTemplate;

            // 6. Extract key values
            $record->repayment_every                    = $loanTemplate['repaymentEvery'] ?? null;
            $record->loan_term_frequency                = $loanTemplate['termFrequency'] ?? null;
            $record->transaction_processing_strategy_id = $loanTemplate['repaymentStrategyOptions'][0]['id'] ?? null;
            $record->legal_form_id                      = $familyTemplate['clientLegalFormOptions'][0]['id'] ?? null;
            $record->salutation_id                      = $familyTemplate['salutation'][0]['id'] ?? null;
            $record->document_type_id                   = $familyTemplate['customerIdentifierOptions'][0]['id'] ?? null;

            // 7. Mark success
            $record->sync_status    = 'success';
            $record->sync_error     = null;
            $record->last_synced_at = now();
            $record->save();

            Log::info("Graviton reference sync OK for borrower {$borrower->id}");
        } catch (\Throwable $e) {
            $record->sync_status = 'failed';
            $record->sync_error  = $e->getMessage();
            $record->save();

            Log::error("Graviton reference sync FAILED for borrower {$borrower->id}: " . $e->getMessage());
        }

        return $record;
    }

    /**
     * ✅ FIXED — authenticate (endpoint + token field)
     */
    protected function authenticate(): void
    {
        $endpoint = config('graviton.auth_endpoint', '/authentication');
        $url      = $this->baseUrl . $endpoint;

        $response = Http::withHeaders([
            'Fineract-Platform-TenantId' => $this->tenantId,
            'Content-Type'               => 'application/json;charset=UTF-8',
            'Accept'                     => 'application/json',
        ])->post($url, [
            'username' => config('graviton.username'),
            'password' => config('graviton.password'),
        ]);

        if (!$response->successful()) {
            Log::error('Graviton auth failed', [
                'url'    => $url,
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new \Exception('Graviton authentication failed: HTTP ' . $response->status());
        }

        $data = $response->json();

        // ✅ FIXED — accept both token field names
        $token = $data['accessToken']
            ?? $data['base64EncodedAuthenticationKey']
            ?? null;

        if (empty($token)) {
            Log::error('Graviton auth: no token', ['response' => $data]);
            throw new \Exception('Graviton auth: no token found in response');
        }

        $this->accessToken = $token;
    }

    /**
     * Common headers with auth.
     */
    protected function headers(): array
    {
        return [
            'Fineract-Platform-TenantId' => $this->tenantId,
            'Authorization'              => 'Bearer ' . $this->accessToken,
            'Accept'                     => 'application/json',
            'Content-Type'               => 'application/json;charset=UTF-8',
        ];
    }

    /**
     * GET /loans/template
     */
    protected function fetchLoanTemplate(?int $productId, ?int $clientId): array
    {
        $query = [
            'templateType'              => 'individual',
            'activeOnly'                => 'true',
            'staffInSelectedOfficeOnly' => 'true',
        ];
        if ($productId) $query['productId'] = $productId;
        if ($clientId)  $query['clientId']  = $clientId;

        $response = Http::withHeaders($this->headers())
            ->get($this->baseUrl . '/loans/template', $query);

        if (!$response->successful()) {
            throw new \Exception('Loan template failed: HTTP ' . $response->status() . ' — ' . $response->body());
        }

        return $response->json();
    }

    /**
     * GET /clients/{clientId}/familymembers/template
     */
    protected function fetchFamilyMemberTemplate(int $clientId = 0): array
    {
        $response = Http::withHeaders($this->headers())
            ->get($this->baseUrl . "/clients/{$clientId}/familymembers/template");

        if (!$response->successful()) {
            throw new \Exception('Family member template failed: HTTP ' . $response->status() . ' — ' . $response->body());
        }

        return $response->json();
    }

    /**
     * GET /creditBureauCheck/template?isActive=true
     */
    protected function fetchCreditBureauTemplate(): array
    {
        $response = Http::withHeaders($this->headers())
            ->get($this->baseUrl . '/creditBureauCheck/template', ['isActive' => 'true']);

        if (!$response->successful()) {
            throw new \Exception('Credit bureau template failed: HTTP ' . $response->status() . ' — ' . $response->body());
        }

        return $response->json();
    }
}