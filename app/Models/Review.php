<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Review extends Model
{
    use HasFactory;
    protected $fillable = ['user_id','business_id','booking_id','overall','punctuality','value','services','avg_rating','business_review','employee_review',"overall_rating"];

    public function business(){
        return $this->belongsTo('App\Models\Business');
    }
    
    public function user(){
        return $this->belongsTo('App\Models\User');
    }

    public function booking(){
        return $this->belongsTo('App\Models\Booking', 'booking_id', 'id');
    }

}
