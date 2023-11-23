<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

class Business extends Model
{
    use HasFactory;
    use Notifiable;

    protected $fillable = ['user_id','title','title_slug','phone','strenght','service_render_location','serving_distance','address','country','city','state','zip','street','lat','lng','reference','payment_method','subscription_plan_id'];

    public function sluggable()
    {
        return [
            'title_slug' => [
             'source' => 'title'
            ]
        ];
    }

    public function businessOwner(){
        return $this->belongsTo('App\Models\User','user_id');
    }
    public function plan(){
        return $this->belongsTo('App\Models\Plan','subscription_plan_id');
    }

    public function businessEmployees(){
        return $this->hasMany('App\Models\Employee');
    }

    public function deals(){
        return $this->hasMany('App\Models\Deal');
    }

    public function businessServices(){
        return $this->hasMany('App\Models\Business_service');
    }

    public function businessHours(){
        return $this->hasMany('App\Models\Business_hour');
    }

    public function servicesProfessional(){
        return $this->hasManyThrough('App\Models\Business_service', 'App\Models\Business_service_employee');
    }

    public function covidPoints(){
        return $this->hasMany('App\Models\Covid_point');
    }

    public function gallery(){
        return $this->hasMany('App\Models\Gallery');
    }

    public function reviews(){
        return $this->hasMany('App\Models\Review');
    }
    public function user_settings(){
        return $this->belongsTo('App\Models\User_setting','user_id',"user_id");
    }
    public function categories(){
        return $this->belongsToMany('App\Models\Category', 'business_categories');
    }
    public function business_types(){
        return $this->belongsToMany('App\Models\Type', 'business_types');
    }

    public function routeNotificationForMail()
    {
        return "iamprogrammer48@gmal.com"; // Replace with the appropriate email attribute of your Business model
    }
}
