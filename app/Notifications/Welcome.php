<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
// use Illuminate\Contracts\Queue\ShouldQueue;
// use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\BroadcastMessage;
// use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
// class Welcome extends Notification implements ShouldQueue,ShouldBroadcast   
class Welcome extends Notification 
{
    use Queueable;
    public $user;
    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct($user)
    {
        $this->user = $user;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        // return ['database','broadcast','mail'];
        return ['database'];
    }

    // public function toMail($notifiable)
    // {
    //     return (new MailMessage)->subject($this->user->first_name.' '.$this->user->last_name.' - Welcome to OnDaQ')->view('emails.welcome',['user' => $this->user]);
    // }

    /**
     * Get the array representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toDatabase($notifiable)
    {
        return [
            'user_id' => $this->user->id,
            'noti_type' => 'welcome',
            'title' => "Welcome to OnDaQ",
            'link' => ''
        ];
    }

    /**
     * Get the broadcastable representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return BroadcastMessage
     */
    // public function toBroadcast($notifiable)
    // {
    //     return new BroadcastMessage([
    //         'user_id' => $this->user->id,
    //         'noti_type' => 'welcome',
    //         'title' => "Welcome to OnDaQ",
    //         'link' => '',
    //     ]);
    // }
}
