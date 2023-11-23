<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\User;

class Payment_transaction extends Model
{
   	public function Booking(){
        return $this->belongsTo('App\Models\Booking');
    }
}
