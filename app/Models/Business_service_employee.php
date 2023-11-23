<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Business_service_employee extends Model
{
    use HasFactory;
    protected $fillable = ['business_id','employee_id','user_id','service_id','business_service_id','created_at','updated_at'];
    public function user(){
        return $this->belongsTo('App\Models\User');
    }
    public function business_service(){
        return $this->belongsTo('App\Models\Business_service');
    }
    public function service(){
        return $this->belongsTo('App\Models\Service');
    }
    public function employee(){
        return $this->belongsTo('App\Models\Employee');
    }
    public function employee_hours(){
        return $this->belongsTo(
            'App\Models\Employee_hour',
            'employee_id',
            'employee_id'
        );
    }

    public function scopeQueueServices($query){
        $query->leftJoin('business_services', 'business_services.id', '=', 'business_service_employees.business_service_id');
    }

    // public function user(){
    //     return $this->belongsTo(
    //         'App\Models\User',
    //         'employee_id',
    //         'id',
    //     );
    // }
}
