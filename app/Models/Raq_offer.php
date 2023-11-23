<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Raq_offer extends Model
{
    use HasFactory;

    protected $fillable = ['user_id','business_id','offer_from','req_a_quote_id','employee_id','price','date','render_location','status'];
}
