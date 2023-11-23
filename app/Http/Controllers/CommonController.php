<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Traits\ApiResponser;
use App\Models\State;
use App\Models\Profession;
use App\Models\Covid_point;
use App\Models\Setting;
use App\Models\Web_pages;
use GeoIP;

class CommonController extends Controller
{
    use ApiResponser;
    public function states($country='US'){
        return $this->success(State::where('country',$country)->get());
    }
    public function professions(){
        return $this->success(Profession::all());
    }
    public function getAddressByIp(Request $request){
        $ip = GeoIP::getClientIP();
    	$geo = GeoIP::getLocation($ip);
    	return $this->success([
            'complete_address' => $geo->city.', '.$geo->state,
            'address_val' => $geo->city.'-'.$geo->state,
            'city'=> $geo->city,
            'state'=> $geo->state,
            'ip'=>$geo->ip
        ]);
    }
    public function covidPoints($type,$slug=''){
        $business_id = 0;
        $covidPoints = Covid_point::where('points_for',$type)->where('business_id',$business_id)->get();
        if ($type == 2 && count($covidPoints) > 0) {
            for ($key=0; $key < count($covidPoints); $key++) { 
                $covidPoints[$key]->value = '';
            }
        }
        return $this->success($covidPoints);
    }
    public function settings(){
        return $this->success(Setting::all());
    }
    public function bannerData($slug){
        $bannerData = Web_pages::where('page_name',$slug)->get();
        return $this->success($bannerData);
    }

}
