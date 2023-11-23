<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Twilio\Rest\Client;

class SendTwilioMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $message;
    protected $recipients;

    public function __construct($message, $recipients)
    {
        $this->message = $message;
        $this->recipients = $recipients;
    }

    public function handle()
    {
        $accountSid = getenv("TWILIO_SID");
        $authToken = getenv("TWILIO_AUTH_TOKEN");
        $twilioNumber = getenv("TWILIO_NUMBER");

        $client = new Client($accountSid, $authToken);

        foreach ($this->recipients as $recipient) {
            $client->messages->create($recipient, [
                'from' => $twilioNumber,
                'body' => $this->message,
            ]);
        }
    }
}
