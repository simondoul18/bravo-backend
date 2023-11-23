<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

class NewBooking extends Notification implements ShouldQueue,ShouldBroadcast   
{
    use Queueable;
    public $business;
    public $customer;
    public $provider;
    public $booking;
    public $services;
    public $type;
    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct($business, $customer, $provider, $booking, $services, $type)
    {
        $this->business = $business;
        $this->customer = $customer;
        $this->provider = $provider;
        $this->booking = $booking;
        $this->services = $services;
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
        $subject = "Booking Confirmation";
        if($this->type == 'provider'){
            $subject = "New Upcoming Appointment";
        }elseif($this->type == 'owner') {
            $subject = "New Booking Alert";
        }
        return (new MailMessage)->subject($subject.' - OnDaQ')->view('emails.booking_confirm',['subject'=>$subject,'business' => $this->business,'customer' => $this->customer,'provider'=>$this->provider,'booking' => $this->booking,'services' => $this->services,'type' => $this->type]);
    }

    /**
     * Get the array representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toDatabase($notifiable)
    {
        $user = $url = $title = $description ='';
        if($this->type == 'user' && !empty($this->customer)){
            // For customer or user
            $user = $this->customer->id;
            $url = '/appointments/upcoming';
            $title = "Booking Confirmation";
            $description = "Your appointment at ".$this->business->title." with ".$this->provider->name." has been successfully booked. We look forward to serving you on ".date('m-d-Y',strtotime($this->booking['booking_date']))." at ".date('H:i a',strtotime($this->booking['booking_start_time'])).".";
        }else if($this->type == 'provider'){
            // For provider
            $user = $this->provider->id;
            $url = '';
            // $url = '/appointments/upcoming';
            $title = "New Upcoming Appointment";
            $description = "You have a new booking at ".$this->business->title." with ".$this->customer->name." on ".date('m-d-Y',strtotime($this->booking['booking_date']))." at ".date('H:i a',strtotime($this->booking['booking_start_time'])).". Please ensure you are ready to provide excellent service.";
        }else if($this->type == 'owner'){
            // For business or Business owner
            $user = $this->business->id;
            $url = '/business/appointments/today';
            $title = "New Booking Alert";
            $description = "A new appointment has been scheduled by ".$this->customer->name." with ".$this->provider->name." for ".date('m-d-Y',strtotime($this->booking['booking_date']))." at ".date('H:i a',strtotime($this->booking['booking_start_time'])).". Please be prepared to welcome your client.";
        }
        return [
            'user_id' => $user,
            'noti_type' => 'booking',
            'title' => $title,
            'description' => $description,
            'link' => $url
        ];
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
            'noti_type' => 'booking-confirm'
        ]);
    }
}
