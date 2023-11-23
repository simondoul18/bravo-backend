<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Deal_service extends Model
{
    use HasFactory;
    public $timestamps = false;
    protected $fillable = ['deal_id','service_id','business_service_id','status'];

    public function service(){
        return $this->belongsTo('App\Models\Service');
    }
    public function business_service(){
        return $this->belongsTo('App\Models\Business_service');
    }
    public function deal(){
        return $this->belongsTo('App\Models\Deal');
    }
}
