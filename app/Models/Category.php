<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use HasFactory;

    // protected $table = 'categories';

    public function services(){
        return $this->hasMany('App\Models\Service');
    }
    public function business_service(){
        return $this->hasMany('App\Models\Business_service');
    }

    public function business_category(){
        return $this->hasOne('App\Models\Business_category');
    }

    // public function servicesProfessional(){
    //     return $this->hasManyThrough('App\Models\Business_service_employee');
    // }
    
    // public function servicesProfessional(){
    //     return $this->hasManyThrough(
    //         'App\Models\Business_service_employee', 
    //         'App\Models\Service',
    //         'category_id',
    //         'service_id',
    //         'id',
    //         'id'
    //     );
    // }

    // public function getAllCategories(){
    //     $this->where('status',1)->orderBy('title', 'asc')->get();
    // }

}
