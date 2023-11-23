<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Stripe_payout extends Model
{
    use HasFactory;
    protected $fillable = ['business_id','payout_id','account_type','balance_transaction','payout_amount','destination_bank','status','created_date','arrival_date'];

    public function businessPayOut(){
        return $this->belongsTo('App\Models\Business');
    }

}
