<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Gallery extends Model
{
    use HasFactory;
    public $timestamps = true;
    protected $fillable = ['business_id','title','thumb','image','description','status'];

    public function gallery_reports(){
        return $this->hasMany('App\Models\Gallery_report');
    }
    public function business(){
        return $this->belongsTo('App\Models\Business');
    }
}
