<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Covid_point extends Model
{
    use HasFactory;
    public function business(){
        return $this->belongsTo('App\Models\Business');
    }
}
