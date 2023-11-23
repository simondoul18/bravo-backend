<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Service extends Model
{
    use HasFactory;
    public $timestamps = false;
    protected $fillable = ['busines_id','category_id','title','description','status'];

    public function category(){
        return $this->belongsTo('App\Models\Category');
    }

    public function businessSelectedServices(){
        return $this->hasMany('App\Models\Business_service');
    }

    public function business_service(){
        return $this->hasOne('App\Models\Business_service');
    }
    
    public function employeeServices(){
        return $this->hasMany('App\Models\Business_service_employee');
    }
}
