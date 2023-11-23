<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Conversation extends Model
{
    protected $fillable = ['booking_id','req_a_quote_id','business_id','user_id','creator_id','created_from','created_at','updated_at'];

    public function messages() {
        return $this->hasMany(UserMessage::class);
    }

    public function user(){
        return $this->belongsTo('App\Models\User');
    }

    public function business(){
        return $this->belongsTo('App\Models\Business');
    }

    public function booking(){
        return $this->belongsTo('App\Models\Booking');
    }
    public function req_a_quote(){
        return $this->belongsTo('App\Models\Req_a_quote');
    }
}
