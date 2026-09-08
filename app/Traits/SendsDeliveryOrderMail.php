<?php

declare(strict_types=1);

namespace App\Traits;

use App\Models\DeliveryOrder;
use App\Models\TeleduceMailLog;
use App\Services\Teleduce\TeleduceMail;
use App\Services\Teleduce\TeleduceRecipient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

trait SendsDeliveryOrderMail
{
    /** TEMP: all DO mails go here until sign-off. Set to null to use real recipients. */
    // protected ?string $doTestRecipient = 'mirakibjaved@encoders.co.in';
    protected ?string $doTestRecipient = 'mirakibjaved@encoders.co.in'; // null;

    /** Signed download link validity — matches the 30-day DO validity. */
    protected int $doLinkValidDays = 30;

    /**
     * @param  array{cc?: string|array, bcc?: string|array}  $options
     * @return array{dealer: TeleduceMailLog|null, borrower: TeleduceMailLog|null}
     */
    public function sendDeliveryOrderMail(DeliveryOrder $do, array $options = []): array
    {
        $link = $this->deliveryOrderLink($do);

        $loan = \App\Models\LoanBasicDetails::find($do->borrower_id);
        
        $shared = [
            'do'             => $do,
            'do_link'        => $this->deliveryOrderLink($do),
            'application_no' => $loan->borrower_id ?? '',
            'lender_name'    => $do->lender_name ?: (\App\Models\Lenders::find($do->lender_id)->name ?? ''),
            'borrower_name'  => $do->borrower_name,
            'dealer_name'    => $do->dealer_name,
            'valid_days'     => $this->doLinkValidDays,
        ];

        $cc  = $options['cc']  ?? [];
        $bcc = $options['bcc'] ?? 'credit.ops@finayo.tech';

        $results = ['dealer' => null, 'borrower' => null];

        $dealerEmail = $this->doTestRecipient ?? $do->dealer_email;

        if ($dealerEmail) {
            $results['dealer'] = $this->dispatchDoMail(
                recipientEmail: $dealerEmail,
                recipientName:  $do->dealer_name ?: 'Dealer',
                subject:        "Delivery Order {$do->do_number} — {$do->borrower_name} (ID: {$loan->borrower_id})",
                data:           $shared + ['recipient_type' => 'dealer'],
                reference:      "do-dealer-{$do->do_number}",
                cc:             $cc,
                bcc:            $bcc,
            );
        }

        $borrowerEmail = $this->doTestRecipient ?? $do->borrower_email;

        if ($borrowerEmail) {
            $results['borrower'] = $this->dispatchDoMail(
                recipientEmail: $borrowerEmail,
                recipientName:  $do->borrower_name ?: 'Borrower',
                subject:        "Your Delivery Order {$do->do_number} (ID: {$loan->borrower_id})",
                data:           $shared + ['recipient_type' => 'borrower'],
                reference:      "do-borrower-{$do->do_number}",
                cc:             $cc,
                bcc:            $bcc,
            );
        }

        return $results;
    }

    protected function deliveryOrderLink(DeliveryOrder $do): string
    {
        return route('delivery-order.download', ['token' => $do->access_token]);
    }

    protected function dispatchDoMail(
        string $recipientEmail,
        string $recipientName,
        string $subject,
        array  $data,
        string $reference,
        array|string $cc = [],
        array|string $bcc = '',
    ): ?TeleduceMailLog {
        try {
            return TeleduceMail::send(
                view:       'emails.delivery_order',
                data:       $data,
                subject:    $subject,
                recipients: [TeleduceRecipient::make($recipientEmail, $recipientName)],
                options:    [
                    'reference' => $reference,
                    'cc'        => $cc,
                    'bcc'       => $bcc,
                ],
            );
        } catch (\Throwable $e) {
            Log::error('[SendsDeliveryOrderMail] Failed to send DO mail', [
                'recipient' => $recipientEmail,
                'reference' => $reference,
                'error'     => $e->getMessage(),
            ]);

            return null;
        }
    }
}