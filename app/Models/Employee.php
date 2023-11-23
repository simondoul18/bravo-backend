<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Employee extends Model
{
    use HasFactory;
    protected $fillable = ['business_id','user_id','role','profession_id','service_rendered','serving_distance','licence','licence_state','station_no','status'];

    public function business(){
        return $this->belongsTo('App\Models\Business');
    }

    public function profession(){
        return $this->belongsTo('App\Models\Profession');
    }

    public function user(){
        return $this->belongsTo('App\Models\User');
    }

    public function services(){
        return $this->belongsToMany('App\Models\Business_service');
    }
    
    public function employeeHours(){
        return $this->hasMany('App\Models\Employee_hour');
    }
    public function businessServiceEmployees(){
        return $this->hasMany('App\Models\Business_service_employee');
    }
}
