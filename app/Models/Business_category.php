<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Business_category extends Model
{
   use HasFactory;
   protected $fillable = ['business_id','category_id'];

   public function business_services(){
      return $this->hasMany('App\Models\Business_service');
   }

   public function category(){
      return $this->belongsTo('App\Models\Category');
   }
}
