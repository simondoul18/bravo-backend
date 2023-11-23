<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Raq_service extends Model
{
    use HasFactory;

    protected $fillable = ['req_a_quote_id','service_id','business_service_id','title','amount','duration','status'];
}
