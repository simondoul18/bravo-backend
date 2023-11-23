<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    use HasFactory;
    protected $fillable = ['tracking_id','deal_id','booking_type','booking_source','user_id','user_firstname','user_lastname','user_email','user_phone','user_address','user_profile_picture','business_id','business_owner_id','business_name','business_image','business_location','business_phone','provider_id','provider_firstname','provider_lastname','provider_email','provider_phone','provider_image','provider_address','service_rendered','rendered_address','rendered_address_apt','rendered_address_lat','rendered_address_lng','booking_duration','booking_price','booking_date','booking_start_time','booking_end_time','ondaq_fee','payment_card_id','additional_note','color','created_at','updated_at'];
   
    public function BoookingServices(){
        return $this->hasMany('App\Models\Booking_service');
    }

    public function BoookingCovidPoints(){
        return $this->hasMany('App\Models\Booking_covid_point');
    }

    public function user(){
        return $this->belongsTo('App\Models\User');
    }

    public function business(){
        return $this->belongsTo('App\Models\Business');
    }
    public function provider(){
        return $this->belongsTo('App\Models\User','provider_id');
    }

    public function BoookingPayout(){
        return $this->hasOne('App\Models\Stripe_payout');
    }
}