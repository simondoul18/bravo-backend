<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

class UpdateBooking extends Notification implements ShouldQueue,ShouldBroadcast  
{
    use Queueable;
    public $business;
    public $customer;
    public $provider;
    public $booking;
    public $type;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct($business, $customer, $provider, $booking, $type)
    {
        $this->business = $business;
        $this->customer = $customer;
        $this->provider = $provider;
        $this->booking = $booking;
        $this->type = $type;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        return ['database','broadcast','mail'];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail($notifiable)
    {
        return (new MailMessage)->subject('Booking Status Updated - OnDaQ')->view('emails.booking_update', ['business' => $this->business, 'customer' => $this->customer, 'provider'=>$this->provider, 'booking' => $this->booking, 'type' => $this->type]);
    }

    /**
     * Get the array representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toDatabase($notifiable)
    {
        if($this->type == 'user'){
            return [
                'user_id' => $this->customer->id,
                'noti_type' => 'update-booking',
                'title' => "Appointment status has updated.",
                'link' => env('APP_URL').'/appointments/upcoming'
            ];
        }else if($this->type == 'provider'){
            return [
                'user_id' => $this->provider->id,
                'noti_type' => 'update-booking',
                'title' => "Appointment status has updated.",
                'link' => env('APP_URL').'/business/appointments/queue'
            ];
        }else if($this->type == 'owner'){
            return [
                'user_id' => $this->business->id,
                'noti_type' => 'update-booking',
                'title' => "Appointment status has updated.",
                'link' => env('APP_URL').'/business/appointments/queue'
            ];
        }
    }

    /**
     * Get the broadcastable representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return BroadcastMessage
     */
    public function toBroadcast($notifiable)
    {
        return new BroadcastMessage([
            'noti_type' => 'booking-update'
        ]);
    }

    /**
     * Get the array representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    // public function toArray($notifiable)
    // {
    //     return [
    //         //
    //     ];
    // }
}
