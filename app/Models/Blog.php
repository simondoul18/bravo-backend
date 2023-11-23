<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Blog extends Model
{
    use HasFactory;

    public function category(){
        return $this->belongsTo('App\Models\BlogCategory');
    }

    public function tags(){
        return $this->belongsTo('App\Models\BlogTags');
    }
    public function admin(){
        return $this->belongsTo('App\Models\Admin');
    }
    public function comments(){
        return $this->hasMany('App\Models\BlogComments');
    }
}
