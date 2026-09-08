<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsService
{
    protected string $baseUrl;
    protected string $apiKey;
    protected string $senderId;

    public function __construct()
    {
        $this->baseUrl  = config('services.sms.base_url');
        $this->apiKey   = config('services.sms.api_key');
        $this->senderId = config('services.sms.sender_id');
    }

    /**
     * Send Link SMS
     */
    public function sendLink(string $to, string $message): SmsResponse
    {
        return $this->send($to, $message, false);
    }

    /**
     * Send OTP SMS
     */
    public function sendOtp(string $to, string $message): SmsResponse
    {
        return $this->send($to, $message, true);
    }

    /**
     * Core sender
     */
    protected function send(string $to, string $message, bool $isOtp): SmsResponse
    {
        try {

            $final_message = $message." - KILPI";
            if($isOtp) {
                $final_message = $message." - KILPIF";
            }
            $query = [
                'key'   => $this->apiKey,
                'text'  => $final_message,
                'route' => 0,
                'from'  => $this->senderId,
                'to'    => $to,
            ];

            if ($isOtp) {
                $query['otp'] = 1;
            }

            $queryString = http_build_query($query);
            $fullUrl = $this->baseUrl . '?' . $queryString;
            
            $response = Http::timeout(15)
                ->connectTimeout(10)
                ->get($fullUrl);

            if ($response->failed()) {

                Log::channel('sms')->error('SMS HTTP Failure', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                    'to'     => $to,
                ]);

                return SmsResponse::failed(
                    'HTTP request failed',
                    $response->status()
                );
            }

            return $this->parseResponse($response->body());

        } catch (\Throwable $e) {

            Log::channel('sms')->error('SMS Exception', [
                'error' => $e->getMessage(),
                'to'    => $to,
            ]);

            return SmsResponse::failed($e->getMessage());
        }
    }

    /**
     * Parse provider raw response
     */
    protected function parseResponse(string $raw): SmsResponse
    {
        $parts = array_map('trim', explode('|', $raw));

        $providerCode = $parts[0] ?? null;

        $isSuccess = $providerCode === '200';

        if (!$isSuccess) {
            \Log::channel('sms')->error('SMS Provider Failure', [
                'raw' => $raw,
            ]);
        }

        return new SmsResponse(
            success: $isSuccess,
            httpCode: $providerCode,
            messageId: $parts[1] ?? null,
            providerMessage: $parts[2] ?? null,
            creditsUsed: $parts[3] ?? null,
            raw: $raw,
            error: $isSuccess ? null : ($parts[2] ?? 'SMS failed')
        );
    }

}
