<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Gallery_like extends Model
{
    use HasFactory;
    public $timestamps = true;
    protected $fillable = ['user_id','gallery_id','business_id','ip_address','status'];

    public function user(){
        return $this->belongsTo('App\Models\User');
    }
    public function business(){
        return $this->belongsTo('App\Models\Business');
    }
    public function gallery(){
        return $this->belongsTo('App\Models\Gallery');
    }
}
