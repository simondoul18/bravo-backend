<?php

namespace App\Helpers;

use File;
use App\Models\Business;
use App\Models\Employee;
use App\Models\Business_service;
use App\Models\User;
use App\Models\Stripe_account;
use App\Models\Stripe_connect_account;
use App\Models\Stripe_log;
use Twilio\Rest\Client;
use Illuminate\Support\Facades\Mail;
use DateTime;
use DateTimeZone;


class Helper
{
    public static function getBusinessByUserId($userId){
        if(!empty($userId)){
            $businessInfo  = Business::where('user_id',$userId)->first();
            return $businessInfo;
        }
    }

    public static function getBusinessById($businessId){
        if(!empty($businessId)){
            $businessInfo  = Business::where('id',$businessId)->first();
            return $businessInfo;
        }
    }
    public static function getBusinessBySlug($businessSlug,$check_status=''){
        if(!empty($businessSlug)){
            $q  = Business::where('title_slug',$businessSlug);
            if ($check_status == 1) {
                $q->where('status',1);
            }
            return $q->first();
        }
    }
    public static function getBusinessEmployees($businessId){
        if(!empty($businessId)){
            $businessEmployees  = Employee::with('user')->where('business_id',$businessId)->get();
            return $businessEmployees;
        }
    }
    public static function getBusinessServices($businessId){
        if(!empty($businessId)){
            $businessInfo  = Business_service::with('service')->where('business_id',$businessId)->get();
            return $businessInfo;
        }
    }
    public static function getBusinessEmployeeById($businessId,$employeeId){
        if(!empty($businessId) && !empty($employeeId)){
            $businessEmployee  = Employee::where('business_id',$businessId)->where('user_id',$employeeId)->first();
            return $businessEmployee;
        }
    }
    public static function getUserById($userId){
        if(!empty($userId)){
            $userInfo  = User::where('id',$userId)->first();
            return $userInfo;
        }
    }
    public static function getUserByEmail($email){
        if(!empty($email)){
            $userInfo  = User::where('email',$email)->first();
            return $userInfo;
        }
    }

    public static function generateRandomString($length = 8) {
        return substr(str_shuffle(str_repeat($x='0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', ceil($length/strlen($x)) )),1,$length);
    }

    public static function upload_picture($picture){
        $file_name = time();
        $file_name .= rand();
        $file_name = sha1($file_name);
        if ($picture) {
            $ext = $picture->getClientOriginalExtension();
            $picture->move(public_path() . "/uploads", $file_name . "." . $ext);
            $local_url = $file_name . "." . $ext;

            $s3_url = url('/').'/uploads/'.$local_url;

            return $s3_url;
        }
        return "";
    }

    public static function delete_picture($picture) {
        File::delete( public_path() . "/uploads/" . basename($picture));
        return true;
    }

    public static function get_day($date) {
        if (!empty($date)) {
            $day = date('w',strtotime($date));
        }else{
            $day = date('w');
        }
        if ($day == '0') {
            $day = '7';
        }
        return $day;
    }

    public static function getUserAssignedBusiness($user_id){
        $data = [];
        $business = Business::where('user_id',$user_id)->first();
        // Get Employees
        $q = Employee::select("business_id")->with("business:id,title,title_slug,profile_pic")->where('user_id',$user_id);
        if (!empty($business)) {
            $data[] = [
                'id'=>$business->id,
                'slug'=>$business->title_slug,
                'title'=>$business->title,
                'picture'=>$business->profile_pic,
                'is_owner'=>1
            ];
            $q->where('business_id',"!=",$business->id);
        }
        $employees = $q->distinct('business_id')->get();
        if (!empty($employees) && count($employees) > 0) {
            foreach ($employees as $key => $emp) {
                $data[] = [
                    'id'=>$emp->business_id,
                    'slug'=>$emp->business->title_slug,
                    'title'=>$emp->business->title,
                    'picture'=>$emp->business->profile_pic,
                    'is_owner'=>0
                ];
            }
        }
        return $data;
    }

    // public function bookingColors($status){
    //     if ($status == 0) {
    //         $color = '#ffc107';
    //     }elseif ($status == 1 || $status == 3) {
    //         $color = '#28a745';
    //     }elseif ($status == 2) {
    //         $color = '#dc3545';
    //     }elseif ($status == 4) {
    //         $color = '#007bff';
    //     }
    //     return $color;
    // }
    public static function bookingColors($status){
        $colors = [
            ['border'=>'#ff8a4b','background'=>'#fff0e8'],      //orange
            ['border'=>'#6fec94','background'=>'#e8fcee'],      //green
            ['border'=>'#d45252','background'=>'#f7dfdf'],      //red
            ['border'=>'#7891cd','background'=>'#e6ecfa'],      //blue
            ['border'=>'#a8a8a8','background'=>'#ededed']       //gray
        ];
        return $colors[$status];
    }

    /**
        * Calculates the great-circle distance between two points, with
        * the Vincenty formula.
        * @param float $lat1 Latitude of start point in [deg decimal]
        * @param float $lon1 Longitude of start point in [deg decimal]
        * @param float $lat2 Latitude of target point in [deg decimal]
        * @param float $lon2 Longitude of target point in [deg decimal]
        * @param float $unit get the distacne in miles or kilometes [M,k]
    */
    public static function getDistance($lat1, $lon1, $lat2, $lon2, $unit= "M") {
        $miles = "";
        if(empty($lat1) || empty($lon1) || empty($lat2) || empty($lon2) )
        {
            return $miles;
        }

        $theta = $lon1 - $lon2;
        $dist = sin(deg2rad($lat1)) * sin(deg2rad($lat2)) +  cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($theta));
        $dist = acos($dist);
        $dist = rad2deg($dist);
        $miles = $dist * 60 * 1.1515;
        $unit = strtoupper($unit);

        if ($unit == "K") {
            return round($miles * 1.609344,1);
        } else {
            return round($miles,1);
        }
    }

    public static function calculateTaxAmount($amount=0,$type = "paye")
	{
		if(empty($amount)){
			return $amount;
		}
       
        //pay as you earn
		if($type == "paye"){
			$percent = ($amount * 7.1) / 100;
            $cent = 0.20;
            $tax = $percent + $cent;
			return round($tax,2);
		}

        //incase of cahrging card-only for whatever reason
		if($type == "stripe"){
			$percent = ($amount * 2.9) / 100;
            $cent = 0.30;
            $tax = $percent + $cent;
			return  round($tax,2);
		}

        //incase of only paouts
        if($type == "payout"){
			$percent = ($amount * 0.25) / 100;
            $cent = 0.25;
            $tax = $percent + $cent;
			return  round($tax,2);
		}

        //in case of bookings (Queue,Appointments)
        if($type == "all")
        {   
			$percent = ($amount * 10.25) / 100; //PAYE + STRIPE FEE + PAYOUT FEE
            $cent = 0.75;
            $tax = $percent + $cent;
			return  round($tax,2);
		}

        //in case of subscription
        if($type == "stripe_Payout")
        {   
			$percent = ($amount * 3.15) / 100; // STRIPE FEE + PAYOUT FEE 
            $cent = 0.55;
            $tax = $percent + $cent;
			return  round($tax,2);
		}
	}


    public static function getUserStripePaymentSource($id = 0, $is_default = 0)
    {
        $data = ["data"=>[],"message"=> "Payment source id is missing","status"=>false];
        
        if(!empty($id))
        {
            $paymentSource = Stripe_account::where('id',$id)->where('status',1)->first();
            if(!empty($paymentSource))
            {
                $data["data"] = $paymentSource;
                $data["status"] = true;
                $data["message"] = "Payment source found successfully";
            }
            else
            {
                $data["message"] = "Payment source doesn't found against id $id ";
            }
           
        }
        return $data;
        
    }

    public static function getBusinessStripeConnectAccount($id = 0)
    {
        $data = ["data"=>[],"message"=> "Business id is missing","status"=>false];
        
        if(!empty($id))
        {
            $connectAccount = Stripe_connect_account::where('business_id',$id)->first();
            if(!empty($connectAccount))
            {
                $data["data"] = $connectAccount;
                $data["status"] = true;
                $data["message"] = "Business connect account found successfully";
            }
            else
            {
                $data["message"] = "Business has no connect account associated ";
            }
           
        }
        return $data;
        
    }

    public static function logStripeResponse($request = [],$response = [])
    {
        $data = ["data"=>[],"message"=> "No response found","status"=>false];
        
        if(!empty($request) || !empty($response))
        {
            $request = json_encode($request);
		    $response = json_encode($response);

            Stripe_log::create(["request"=> $request, "response" =>$response ]);
            if(!empty($logged))
            {
                $data["data"] = $connectAccount;
                $data["status"] = true;
                $data["message"] = "Business connect account found successfully";
            }
            else
            {
                $data["message"] = "Business has no connect account associated ";
            }
           
        }
        return $data;
        
    }

     /**
     * Sends sms to user using Twilio's programmable sms client.
     *
     * @param String $message Body of sms
     * @param Number $recipients Number of recipient
     * @return void
     */
    public static function sendMessage($message, $recipients,$multiple= false)
    {
        $account_sid = getenv("TWILIO_SID");
        $auth_token = getenv("TWILIO_AUTH_TOKEN");
        $twilio_number = getenv("TWILIO_NUMBER");

        $client = new Client($account_sid, $auth_token);
        if(!empty($multiple))
        {
            foreach ($recipients as $recipient) {
                $client->messages->create($recipient, ['from' => $twilio_number, 'body' => $message]);
            }
        }else{
             $client->messages->create($recipients, ['from' => $twilio_number, 'body' => $message]);
        }
        
       
    }

    public static function convertToTimeZone($targetTimezone) {
        // Get the current datetime in the server's default timezone
        $currentDatetime =  new DateTime();
    
        // Create a DateTimeZone object for the target timezone
        $targetDatetimezone = new DateTimeZone($targetTimezone);
    
        // Set the target timezone for the current datetime
        $currentDatetime->setTimezone($targetDatetimezone);
        

        // Format the converted datetime for display
        $formattedDate = $currentDatetime->format("Y-m-d");
        $formattedTime = $currentDatetime->format("H:i:s");
        $formattedDatetime = $currentDatetime->format("Y-m-d H:i:s");
        $formattedtimeHI = $currentDatetime->format("H:i");
        $formattedtimesMinutes = $currentDatetime->format("i");
    
        return ["date"=>$formattedDate,"time"=>$formattedTime,"datetime"=>$formattedDatetime,"hourMinues" => $formattedtimeHI,"minutes" => $formattedtimesMinutes];
    }
}
