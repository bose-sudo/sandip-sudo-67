<?php
namespace App\Services;

use Twilio\Rest\Client;

class TwilioService
{
    protected $twilio;

    public function __construct()
    {
        $sid = config('services.twilio.sid');
        $token = config('services.twilio.token');
    
        if (!$sid || !$token) {
            throw new \Exception('Twilio SID and Auth Token are required');
        }
    
        $this->twilio = new Client($sid, $token);
    }

    public function sendSms($to, $message)
    {
        return $this->twilio->messages->create($to, [
            // 'from' => env('TWILIO_PHONE_NUMBER'),
            'from' => config('services.twilio.from'),
            'body' => $message,
        ]);
    }
}
