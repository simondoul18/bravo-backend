<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Stripe_payment extends Model
{
    use HasFactory;
    protected $fillable = ['business_id','booking_id','booking_ref','total_amount','total_fee','fee_details','net_amount','created','available_on','charge_id','application_fee_amount','intent_id','payment_status',"amount_status"];

    public function businessBookingPayments(){
        return $this->belongsTo('App\Models\Business');
    }

}
