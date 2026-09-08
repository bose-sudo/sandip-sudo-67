<?php

namespace App\Services\Teleduce;

use App\Models\TeleduceMailLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Throwable;

/**
 * TeleduceMail — Laravel wrapper for the Teleduce email API.
 *
 * Usage (blade view):
 *   TeleduceMail::send(
 *       view: 'emails.invoice',
 *       data: ['invoice' => $invoice],
 *       subject: 'Your Invoice',
 *       recipients: [
 *           TeleduceRecipient::make('user@example.com', 'John'),
 *       ],
 *   );
 *
 * Usage (raw HTML):
 *   TeleduceMail::sendRaw(
 *       html: '<p>Hello!</p>',
 *       subject: 'Hi',
 *       recipients: [TeleduceRecipient::make('user@example.com')],
 *   );
 */
class TeleduceMail
{
    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Send using a Blade view — mirrors how Mail::send() works.
     *
     * @param  string                $view       Blade view path e.g. 'emails.invoice'
     * @param  array                 $data       Data passed to the view
     * @param  string                $subject
     * @param  TeleduceRecipient[]   $recipients
     * @param  array                 $options    Override from_email, from_name, reply_to, reference
     */
    public static function send(
        string $view,
        array  $data,
        string $subject,
        array  $recipients,
        array  $options = [],
    ): TeleduceMailLog {
        $html = View::make($view, $data)->render();

        return static::sendRaw(
            html:       $html,
            subject:    $subject,
            recipients: $recipients,
            options:    $options,
        );
    }

    /**
     * Send a raw HTML string directly.
     *
     * @param  string                $html
     * @param  string                $subject
     * @param  TeleduceRecipient[]   $recipients
     * @param  array                 $options
     */
    public static function sendRaw(
        string $html,
        string $subject,
        array  $recipients,
        array  $options = [],
    ): TeleduceMailLog {
        $service = new static();

        return $service->dispatch(
            html:       $html,
            subject:    $subject,
            recipients: $recipients,
            options:    $options,
        );
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    private function dispatch(
        string $html,
        string $subject,
        array  $recipients,
        array  $options,
    ): TeleduceMailLog {
        $log = new TeleduceMailLog();
        $log->subject    = $subject;
        $log->recipients = array_map(fn(TeleduceRecipient $r) => $r->toArray(), $recipients);
        $log->from_email = $options['from_email'] ?? config('teleduce.from_email');
        $log->from_name  = $options['from_name']  ?? config('teleduce.from_name');
        $log->reference  = $options['reference']  ?? null;
        $log->setHtmlContent($html);

        try {
            $this->validateRecipients($recipients);

            $payload  = $this->buildPayload($html, $subject, $recipients, $options);
            $apiUrl   = $this->buildUrl();
            $response = Http::timeout(config('teleduce.timeout', 30))
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($apiUrl, $payload);

            $responseBody = $response->body();
            $log->setApiResponse($responseBody);

            if ($response->failed()) {
                throw new \RuntimeException(
                    "Teleduce API returned HTTP {$response->status()}: {$responseBody}"
                );
            }

            $log->status = 'sent';
            $log->save();

            Log::info('[Teleduce] Mail sent', [
                'subject'    => $subject,
                'recipients' => count($recipients),
                'reference'  => $log->reference,
            ]);

        } catch (Throwable $e) {
            $log->status        = 'failed';
            $log->error_message = $e->getMessage();
            $log->save();

            Log::error('[Teleduce] Mail failed', [
                'subject'    => $subject,
                'error'      => $e->getMessage(),
                'reference'  => $log->reference,
            ]);

            // Re-throw so callers can handle if needed
            throw $e;
        }

        return $log;
    }

    private function buildPayload(
        string $html,
        string $subject,
        array  $recipients,
        array  $options,
    ): array {
        $message = [
            'html_content' => $html,
            'subject'      => $subject,
            'from_mail'    => $options['from_email'] ?? config('teleduce.from_email'),
            'from_name'    => $options['from_name']  ?? config('teleduce.from_name'),
            'reply_to'     => $options['reply_to']   ?? config('teleduce.reply_to'),
            'to_recipients' => array_map(
                fn(TeleduceRecipient $r) => $r->toArray(),
                $recipients
            ),
        ];

        return [
            'mail_datas' => [
                'message' => $message,
            ],
        ];
    }

    private function buildUrl(): string
    {
        $base       = rtrim(config('teleduce.base_url'), '/');
        $apiKey     = config('teleduce.api_key');
        $campaignId = config('teleduce.campaign_id');

        if (empty($apiKey)) {
            throw new \RuntimeException(
                'TELEDUCE_API_KEY is not set in .env'
            );
        }

        return "{$base}/{$apiKey}/{$campaignId}/";
    }

    private function validateRecipients(array $recipients): void
    {
        if (empty($recipients)) {
            throw new \InvalidArgumentException('At least one recipient is required.');
        }

        foreach ($recipients as $index => $recipient) {
            if (!$recipient instanceof TeleduceRecipient) {
                throw new \InvalidArgumentException(
                    "Recipient at index {$index} must be an instance of TeleduceRecipient."
                );
            }

            if (!filter_var($recipient->email, FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException(
                    "Invalid email address: {$recipient->email}"
                );
            }
        }
    }
}