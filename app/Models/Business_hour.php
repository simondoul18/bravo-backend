<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Business_hour extends Model
{
    use HasFactory;
    public $timestamps = false;
    
    protected $fillable = ['business_id','title','day','start_time','end_time'];

}
