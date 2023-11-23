<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasApiTokens;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'google_id',
        'facebook_id',
        'apple_id',
        'name',
        'first_name',
        'last_name',
        'email',
        'password',
        'phone',
        'gender',
        'picture',
        'address','country','city','state','zip','street','lat','lng'
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    public function business(){
        return $this->hasOne('App\Models\Business');
    }

    public function employees(){
        return $this->hasOne('App\Models\Business_service_employee');
    }
    
    public function comments(){
        return $this->hasMany('App\Models\BlogComments');
    }

    public function receivesBroadcastNotificationsOn()
    {
        return 'users.'.$this->id;
    }
    public function routeNotificationForTwilio()
    {
        return $this->phone;
    }
}