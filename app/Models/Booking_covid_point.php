<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Booking_covid_point extends Model
{
    use HasFactory;
    protected $fillable = ['booking_id','covid_point_id','point','answer','created_at','updated_at'];

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
