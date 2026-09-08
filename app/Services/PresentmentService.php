<?php
// app/Services/PresentmentService.php

namespace App\Services;

use App\Models\EnachDebitRequest;
use App\Models\EnachDebitResponse;
use App\Models\LoanBasicDetails;
use Exception;
use Illuminate\Support\Facades\Log;

class PresentmentService
{
    private string $merchantKey;
    private string $merchantSalt;
    private string $baseUrl;
    private int    $timeout;

    public function __construct()
    {
        $this->merchantKey  = config('easebuzz.merchant_key');
        $this->merchantSalt = config('easebuzz.merchant_salt');
        $this->baseUrl      = config('easebuzz.base_url');
        $this->timeout      = config('easebuzz.timeout', 30);
    }

    /**
     * Present a single EMI via Easebuzz API.
     *
     * @param  array $payload  Required keys:
     *                         borrower_id, transaction_id, amount,
     *                         presentment_date, schedule_id
     *
     * @return array {
     *   success: bool,
     *   http_code: int,
     *   merchant_request_number: string,
     *   request_payload: array,
     *   response_payload: array,
     *   error: string|null
     * }
     *
     * @throws Exception
     */
    public function present(array $payload): array
    {
        $this->validatePayload($payload);

        // ── 1. Persist debit request record ──────────────────────────────────
        $debitRequest = $this->createDebitRequest($payload);

        // ── 2. Build merchant_request_number ─────────────────────────────────
        $dbtRequestNo          = str_pad($debitRequest->id, 3, '0', STR_PAD_LEFT);
        $merchantRequestNumber = 'FIN-' . now('Asia/Kolkata')->format('YmdHis') . '-' . $dbtRequestNo . 'EDR';

        // ── 3. Generate SHA-512 authorization hash ────────────────────────────
        // format amount EXACTLY like controller
        // $amount = number_format((float)$payload['amount'], 2, '.', '');
        $amount = (int) $payload['amount'];
        $authString = implode('|', [
            $this->merchantKey,
            $payload['transaction_id'],
            $merchantRequestNumber,
            $amount,
            $this->merchantSalt,
        ]);
        $authHash = hash('sha512', $authString);

        $debitRequest->merchant_request_number = $merchantRequestNumber;
        $debitRequest->authorization           = $authHash;
        $debitRequest->save();

        // ── 4. Build API request body ─────────────────────────────────────────
        $requestBody = [
            'key'                     => $this->merchantKey,
            'transaction_id'          => $payload['transaction_id'],
            'amount'                  => $amount,
            // 'presentment_date'        => $payload['presentment_date'],
            'merchant_request_number' => $merchantRequestNumber,
            'submerchant_id'          => '',
        ];

        // ── 5. Call Easebuzz API ──────────────────────────────────────────────
        [$httpCode, $response] = $this->callApi(
            $this->baseUrl . 'autocollect/v1/mandate/presentment/',
            $requestBody,
            $authHash
        );

        // ── 6. Persist API response ───────────────────────────────────────────
        if (!empty($response['data'])) {
            $this->saveDebitResponse($response['data'], $merchantRequestNumber);
        }

        // ── 7. Return structured result ───────────────────────────────────────
        $success = $httpCode === 200 && ($response['success'] ?? false) === true;

        return [
            'success'                 => $success,
            'http_code'               => $httpCode,
            'merchant_request_number' => $merchantRequestNumber,
            'request_payload'         => $requestBody,
            'response_payload'        => $response,
            'error'                   => $success
                ? null
                : ($response['message'] ?? "HTTP {$httpCode} — API returned failure"),
        ];
    }

    // ── Private Helpers ───────────────────────────────────────────────────────

    private function validatePayload(array $payload): void
    {
        foreach (['borrower_id', 'transaction_id', 'amount', 'presentment_date'] as $field) {
            if (empty($payload[$field])) {
                throw new Exception("PresentmentService: Missing required field [{$field}]");
            }
        }
    }

    private function createDebitRequest(array $payload): EnachDebitRequest
    {
        $req                   = new EnachDebitRequest();
        $req->borrower_id      = $payload['borrower_id'];
        $req->transaction_id   = $payload['transaction_id'];
        $req->amount           = $payload['amount'];
        $req->presentment_date = $payload['presentment_date'];
        $req->modified_by      = $payload['modified_by'] ?? null;
        $req->ip               = $payload['ip'] ?? request()->ip();
        $req->save();

        return $req;
    }

    private function saveDebitResponse(array $data, string $merchantRequestNumber): void
    {
        try {
            $resp                              = new EnachDebitResponse();
            $resp->response_id                 = $data['id'] ?? '';
            $resp->pg_transaction_id           = $data['pg_transaction_id'] ?? '';
            $resp->amount                      = $data['amount'] ?? '';
            $resp->presentment_date            = $data['presentment_date'] ?? null;
            $resp->status                      = $data['status'] ?? '';
            $resp->net_amount                  = $data['net_amount'] ?? '';
            $resp->transaction_reference_number= $data['transaction_reference_number'] ?? '';
            $resp->bank_reference_number       = $data['bank_reference_number'] ?? '';
            $resp->status_at_bank              = $data['status_at_bank'] ?? '';
            $resp->split_payments              = isset($data['split_payments'])
                                                    ? json_encode($data['split_payments'])
                                                    : '';
            $resp->response_meta               = isset($data['response_meta'])
                                                    ? json_encode($data['response_meta'])
                                                    : '';
            $resp->merchant_request_number     = $merchantRequestNumber;
            $resp->response_created_at         = isset($data['created_at'])
                                                    ? now('Asia/Kolkata')->parse($data['created_at'])
                                                    : null;
            $resp->save();
        } catch (Exception $e) {
            // Non-fatal — log and continue. The presentment_log captures the full response anyway.
            Log::warning("[PresentmentService] Failed to save EnachDebitResponse: " . $e->getMessage());
        }
    }

    /**
     * @return array [int $httpCode, array $decoded]
     * @throws Exception
     */
    private function callApi(string $url, array $body, string $authHash): array
    {
        $curl = curl_init();
        if ($curl === false) {
            throw new Exception('PresentmentService: Failed to initialize cURL');
        }

        curl_setopt_array($curl, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING       => '',
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => [
                'X-EB-MERCHANT-KEY: ' . $this->merchantKey,
                'Content-Type: application/json',
                'Authorization: ' . $authHash,
            ],
        ]);

        $raw       = curl_exec($curl);
        $httpCode  = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);

        if ($raw === false) {
            throw new Exception('PresentmentService: cURL Error: ' . $curlError);
        }

        $decoded = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception(
                'PresentmentService: JSON decode error: ' . json_last_error_msg() .
                ' | Raw: ' . substr($raw, 0, 500)
            );
        }

        return [$httpCode, $decoded];
    }
}