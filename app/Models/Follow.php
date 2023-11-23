<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Follow extends Model
{
    use HasFactory;
    public $timestamps = true;
    protected $fillable = ['business_id','user_id'];

    public function business(){
        return $this->belongsTo('App\Models\Business');
    }

    public function user(){
        return $this->belongsTo('App\Models\User');
    }
}
