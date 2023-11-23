<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Stripe_transfer extends Model
{
    use HasFactory;
    protected $fillable = ['business_id','booking_id','transfer_id','balance_transaction_id','destination_account','destination_payment','source_type','object','created','amount_reversed'];

    public function businessTransfer(){
        return $this->belongsTo('App\Models\Business');
    }

}
