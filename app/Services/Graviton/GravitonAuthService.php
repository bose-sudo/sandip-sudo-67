<?php

namespace App\Services\Graviton;

use App\Models\GravitonReferenceData;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GravitonAuthService
{
    public function getToken(): string
    {
        return Cache::remember('graviton_access_token', now()->addMinutes(45), fn () => $this->authenticate());
    }

    public function authenticate(): string
    {
        $url = config('graviton.base_url') . config('graviton.auth_endpoint');

        $response = Http::withHeaders([
            'Content-Type'               => 'application/json;charset=UTF-8',
            'Accept'                     => 'application/json',
            'Fineract-Platform-TenantId' => config('graviton.tenant_id'),
        ])->post($url, [
            'username' => config('graviton.username'),
            'password' => config('graviton.password'),
        ]);

        if (!$response->successful()) {
            Log::error('Graviton auth failed', ['status' => $response->status(), 'body' => $response->body()]);
            throw new \Exception('Graviton auth failed: HTTP ' . $response->status());
        }

        $data  = $response->json();
        $token = $data['accessToken'] ?? $data['base64EncodedAuthenticationKey'] ?? null;

        if (empty($token)) {
            Log::error('Graviton auth: no token', ['response' => $data]);
            throw new \Exception('Graviton auth: no token found');
        }

        // Save office
        if (!empty($data['officeId'])) {
            GravitonReferenceData::updateOrCreate(
                ['type' => 'office', 'graviton_id' => $data['officeId']],
                [
                    'label'        => $data['officeName'] ?? null,
                    'is_active'    => true,
                    'raw_response' => $data,
                    'synced_at'    => now(),
                ]
            );
        }

        return $token;
    }

    public function client()
    {
        return Http::withHeaders([
            'Content-Type'               => 'application/json;charset=UTF-8',
            'Accept'                     => 'application/json',
            'Fineract-Platform-TenantId' => config('graviton.tenant_id'),
            'Authorization'              => 'Bearer ' . $this->getToken(),
        ])->baseUrl(config('graviton.base_url'));
    }
}