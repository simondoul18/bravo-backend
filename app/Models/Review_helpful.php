<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Review_helpful extends Model
{
    use HasFactory;
    protected $fillable = ['user_id','business_id','review_id'];

    public function business(){
        return $this->belongsTo('App\Models\Business');
    }
    
    public function user(){
        return $this->belongsTo('App\Models\User');
    }

    public function review(){
        return $this->belongsTo('App\Models\Review');
    }
}
