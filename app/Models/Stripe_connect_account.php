<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Stripe_connect_account extends Model
{
    use HasFactory;
    protected $fillable = ['business_id','access_token','refresh_token','token_type','stripe_publishable_key','stripe_user_id','scope'];

    public function business(){
        return $this->belongsTo('App\Models\Business');
    }
}
