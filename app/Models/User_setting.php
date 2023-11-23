<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class User_setting extends Model
{
    use HasFactory;
    protected $fillable = ['user_id','queue_status'];

    public function user(){
        return $this->belongsTo('App\Models\User');
    }

    public function card(){
        return $this->belongsTo('App\Models\Stripe_account','card_for_auto_renew');
    }
}
