<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReportSubmitted extends Notification
{
    use Queueable;
    public $reportSubject;
    public $customerMessage;
    public $reportedReview;
    public $businessLink;
    public $businessName;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct($reportSubject,$customerMessage,$reportedReview,$businessName="",$businessLink)
    {
        $this->reportSubject = $reportSubject;
        $this->customerMessage = $customerMessage;
        $this->reportedReview = $reportedReview;
        $this->businessName = $businessName;
        $this->businessLink = $businessLink;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->subject('Report Received')
            ->markdown('emails.reportReceived', [
                'reportSubject' => $this->reportSubject,
                'customerMessage' => $this->customerMessage,
                'reportedReview' => $this->reportedReview,
                'businessName' => $this->businessName,
                'businessLink' => $this->businessLink,
            ]);
    }

    /**
     * Get the array representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toArray($notifiable)
    {
        return [
            //
        ];
    }
}
