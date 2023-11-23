<?php

namespace App\Http\Controllers;
use App\Traits\ApiResponser;
use Illuminate\Http\Request;

use App\Models\Business;
use App\Models\Category;
use App\Models\Service;
use App\Helpers\Helper;

use DB;

class SearchController extends Controller
{
    use ApiResponser;

    public function popularServices(){
        $services = Service::where('status',1)->orderBy('hits','desc')->limit(6)->get();
        return $this->success($services);
    }

    public function searchSuggestions($keywork = '')
    {
        if (!empty($keywork)) {
            $businesses = Business::where('title','like','%'.$keywork.'%')->where('status',1)->get();
            $services = Category::with(['services'=> function ($query) use ($keywork) {
                $query->where('services.title','like','%'.$keywork.'%')->where('services.status',1)->where('services.busines_id',0);
            }])->where('status',1)->get();
            $data = [];
            if (!empty($services) && $services->count() > 0) {
                foreach ($services as $key => $cate) {
                    if (!empty($cate->services) && $cate->services->count() > 0) {
                        $label = $cate->title;
                        $options = [];
                        foreach ($cate->services as $key => $service) {
                            $options[] = ['label'=>$service->title,'value'=>['id'=>$service->id,'name'=>$service->title,'icon'=>'','type'=>'service']];
                        }
                        if (!empty($options)) {
                            $data[] = ['label'=>$label,'options'=>$options];
                        }
                    }
                }
            }
            if (!empty($businesses)) {
                $label = "Businesses";
                $options = [];
                foreach ($businesses as $key => $business) {
                    $options[] = ['label'=>$business->title,'value'=>['id'=>$business->id,'name'=>$business->title,'slug'=>$business->title_slug,'icon'=>$business->profile_pic,'type'=>'business']];
                }
                if (!empty($options)) {
                    $data[] = ['label'=>$label,'options'=>$options];
                }
            }
            return response()->json($data);

            // return $this->success([
            //     [
            //       label: 'Business',
            //       options: [{ value: {name:'Barber',icon:'icon.png'}, label: 'Barber' },{ value: {name:'Spa',icon:'icon.png'}, label: 'Spa' }],
            //     ],
            //     [
            //       label: 'Marvel',
            //       options: [{ value: {name:'Barber',icon:'icon.png'}, label: 'Barber' },{ value: {name:'Spa',icon:'icon.png'}, label: 'Spa' }],
            //     ],
            // ]);
        }else{
            return response()->json([]);
        }
    }

    public function getLocations($keywork = ''){
        if (!empty($keywork) && strlen($keywork) > 2) {
            $url = "https://maps.googleapis.com/maps/api/place/autocomplete/json?&key=".env('MAP_KEY')."&components=country:US&type=(regions)&input=".str_replace(' ', '+', $keywork);
            // if (preg_match('/^[0-9]+$/', $keywork)) {
            //     $url = "https://maps.googleapis.com/maps/api/geocode/json?address=".$keywork."&key=".env('MAP_KEY');
            // }else{
            //     $url = "https://maps.googleapis.com/maps/api/place/autocomplete/json?&key=".env('MAP_KEY')."&components=country:US&type=(cities)&input=".str_replace(' ', '+', $keywork);
            // }
            $curl = curl_init();
            curl_setopt_array($curl, array(
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => "",
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => "GET",
                CURLOPT_SSL_VERIFYHOST => 0, // Comment this on live server
                CURLOPT_SSL_VERIFYPEER => 0, // Comment this on live server
                //CURLOPT_POSTFIELDS => $postData,
                CURLOPT_HTTPHEADER => array(
                    //"Authorization: Bearer ".$apiKey,
                    "Content-Type: application/json"
                ),
            ));

            $response = curl_exec($curl);
            $err = curl_error($curl);

            curl_close($curl);
            $options=[];
            $options[] = ["label"=>"Current Location","value"=>"current-location"];
            if ($err) {
                return response()->json("cURL Error #:" . $err);
            } else {
                $curl_data = json_decode($response);
                if (isset($curl_data->predictions)) {
                    //return response()->json($curl_data->predictions);
                    foreach($curl_data->predictions as $prediction) {
                        $add_str = str_replace(", USA", "", $prediction->description);
                        if (in_array("postal_code", $prediction->types)){
                            $add_arr = explode(", ",$add_str);
                            $value = $add_arr[0].'-'.substr($add_arr[1],0,2);
                        }else{
                            $value = str_replace(", ", "-", $add_str);
                        }
                        $options[] = [
                            'label' => $add_str,
                            'value' => $value
                        ];
                    }
                }
            }
            return response()->json($options);
        }
    }




        // function getBusinessProfileDetails($slug){
        //     $businessInfo  = Business::with('businessHours')->where('title_slug',$slug)->first();
        //     return response()->json($businessInfo);
        // }

        // function getBusinessProfileInfo($businessId,$type=""){
        //     if($type == "services"){
        //         $businessInfo = Helper::getBusinessServices($businessId);
        //     }else if("deals"){

        //     }else if("reviews"){

        //     }
        //     return $businessInfo;
        // }

        // public function getLatLng($place=null){
        //     $response=array();
        //     $suggest="";
            
        //     // Get cURL resource
        //     $curl = curl_init();
        //     // Set some options - we are passing in a useragent too here
        //     curl_setopt_array($curl, array(
        //         CURLOPT_RETURNTRANSFER => 1,
        //         CURLOPT_URL => 'https://maps.googleapis.com/maps/api/geocode/json?key=AIzaSyDOA0ZSPIIOrMb91RuLVPHM-P46_LhJr6Y&address='.str_replace(' ','+',$place),
        //         CURLOPT_USERAGENT => 'Codular Sample cURL Request'
        //     ));
        //     // Send the request & save response to $resp
        //     $resp = curl_exec($curl);
        //     $resp=json_decode($resp);
            
        //     return $resp;       
        // }
        // public function filterAddress($components, $type){
        //     return array_filter($components, function($component) use ($type) {
        //         return array_filter($component->types, function($data) use ($type) {
        //             return $data == $type;
        //         });
        //     });
        // }




    
}