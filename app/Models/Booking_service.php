<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Booking_service extends Model
{
    use HasFactory;
    protected $fillable = ['title','booking_id','category_id','business_category_id','service_id','duration','tax','initial_duration','gap','finish_duration','org_cost','cost','is_requested','description','commission','business_service_id','created_at','updated_at'];

    public function product()
    {
        return $this->belongsTo('App\Models\Booking');
    }


    // public function professionals1(){
    //     return $this->hasManyThrough('App\Models\Business_service_employee','App\Models\User');
    // }

    // public function scopeUser($query){
    //     return $query->with('professionals1', 'professionals');
    // }
    
}
