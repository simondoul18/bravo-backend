<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Employee_hour extends Model
{
    use HasFactory;
    public $timestamps = false;
    protected $fillable = ['business_id','employee_id','title','day','isOpen','start_time','end_time','isBreak','breakStart','breakEnd'];
    
    public function employees(){
        return $this->belongsTo('App\Models\Employee');
    }
}
