<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Business_service extends Model
{
    use HasFactory;
    protected $fillable = ['business_id','default_service','category_id','business_category_id','service_id','duration','tax','initial_duration','gap','finish_duration','org_cost','cost','is_requested','description','commission','only_for_booking','status','created_at','updated_at'];

    public function business(){
        return $this->belongsTo('App\Models\Business');
    }
    public function business_category(){
        return $this->belongsTo('App\Models\Business_category');
    }
    public function service(){
        return $this->belongsTo('App\Models\Service');
    }

    public function category(){
        return $this->belongsTo('App\Models\Category');
    }

    public function professionals(){
        return $this->hasMany('App\Models\Business_service_employee');
    }


    // public function professionals1(){
    //     return $this->hasManyThrough('App\Models\Business_service_employee','App\Models\User');
    // }

    // public function scopeUser($query){
    //     return $query->with('professionals1', 'professionals');
    // }
    
}
