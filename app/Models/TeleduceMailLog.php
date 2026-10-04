<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class TeleduceMailLog extends Model
{
    use HasFactory;
    protected $table = 'teleduce_mail_logs';
    protected $primaryKey = "id";

    protected $fillable = [
        'status',
        'subject',
        'recipients',
        'from_email',
        'from_name',
        'html_content_encrypted',
        'api_response_encrypted',
        'error_message',
        'reference',
    ];

    protected $casts = [
        'recipients' => 'array',
    ];

    // -------------------------------------------------------------------------
    // Encryption helpers
    //
    // We use Laravel's Crypt facade which ties to APP_KEY in .env.
    // APP_KEY never changes on cache:clear / config:cache / optimize:clear,
    // so encrypted values stored in DB are always decryptable.
    // -------------------------------------------------------------------------

    /**
     * Store encrypted HTML content.
     */
    public function setHtmlContent(string $html): void
    {
        if (config('teleduce.encrypt_logs')) {
            $this->html_content_encrypted = Crypt::encryptString($html);
        } else {
            $this->html_content_encrypted = $html;
        }
    }

    /**
     * Retrieve and decrypt HTML content.
     */
    public function getHtmlContent(): ?string
    {
        if (!$this->html_content_encrypted) {
            return null;
        }

        if (config('teleduce.encrypt_logs')) {
            return Crypt::decryptString($this->html_content_encrypted);
        }

        return $this->html_content_encrypted;
    }

    /**
     * Store encrypted API response.
     */
    public function setApiResponse(string $response): void
    {
        if (config('teleduce.encrypt_logs')) {
            $this->api_response_encrypted = Crypt::encryptString($response);
        } else {
            $this->api_response_encrypted = $response;
        }
    }

    /**
     * Retrieve and decrypt API response.
     */
    public function getApiResponse(): ?string
    {
        if (!$this->api_response_encrypted) {
            return null;
        }

        if (config('teleduce.encrypt_logs')) {
            return Crypt::decryptString($this->api_response_encrypted);
        }

        return $this->api_response_encrypted;
    }
}
