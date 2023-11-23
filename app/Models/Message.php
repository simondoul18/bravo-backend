<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\User;

class Message extends Model
{
    // public function user_messages() {
    //     return $this->hasMany(UserMessage::class);
    // }

    public function user() {
        return $this->belongsTo(User::class, 'sender_id');
    }
    public function business() {
        return $this->belongsTo(Business::class, 'sender_id');
    }
    // public function users() {
    //     return $this->belongsToMany(User::class, 'user_messages',
    //         'message_id', 'sender_id')
    //         ->withTimestamps();
    // }
}
