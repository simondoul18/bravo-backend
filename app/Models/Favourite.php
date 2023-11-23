<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Favourite extends Model
{
    use HasFactory;
    public $timestamps = true;
    protected $fillable = ['business_id','user_id','updated_at','created_at'];

    public function business(){
        return $this->belongsTo('App\Models\Business');
    }

    public function user(){
        return $this->belongsTo('App\Models\User');
    }
}
