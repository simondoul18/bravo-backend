<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Req_a_quote extends Model
{
    use HasFactory;

    protected $fillable = ['tracking_id','user_id','employee_id','employee_user_id','business_id','render_location','price','description','booking_date','status','created_at','updated_at'];

    public function user(){
        return $this->belongsTo('App\Models\User');
    }
    public function provider(){
        return $this->belongsTo('App\Models\User','employee_user_id');
    }
    public function employee(){
        return $this->belongsTo('App\Models\Employee');
    }
    public function business(){
        return $this->belongsTo('App\Models\Business');
    }

    public function services(){
        return $this->hasMany('App\Models\Raq_service');
    }
    public function offers(){
        return $this->hasMany('App\Models\Raq_offer');
    }
}
