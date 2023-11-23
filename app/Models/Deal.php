<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Deal extends Model
{
    use HasFactory;
    protected $fillable = ['business_id','user_id','employee_id','title','slug','description','banner','startDate','endDate','unit','discount_value','total_price','discounted_price','total_duration','is_package','status'];
    
    protected static function boot()
    {
        parent::boot();
  
        static::created(function ($deal) {
            $deal->slug = $deal->createSlug($deal->title);
            $deal->save();
        });
    }
    private function createSlug($title){
        if (static::whereSlug($slug = Str::slug($title))->exists()) {
            $max = static::whereTitle($title)->latest('id')->skip(1)->value('slug');
            if (is_numeric($max[-1])) {
                return preg_replace_callback('/(\d+)$/', function ($mathces) {
                    return $mathces[1] + 1;
                }, $max);
            }
            return "{$slug}-2";
        }
        return $slug;
    }
    
    public function deal_services(){
        return $this->hasMany('App\Models\Deal_service');
    }
    public function user(){
        return $this->belongsTo('App\Models\User');
    }
    // public function business_service(){
    //     return $this->belongsTo('App\Models\Business_service');
    // }
    public function business(){
        return $this->belongsTo('App\Models\Business');
    }

    // public function sluggable(): array
    // {
    //     return [
    //         'slug' => [
    //             'source' => 'title'
    //         ]
    //     ];
    // }
}
