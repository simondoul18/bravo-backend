<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    public function booking(){
        return $this->belongsTo('App\Models\Booking');
    }
    public function user(){
        return $this->belongsTo('App\Models\User');
    }
}
