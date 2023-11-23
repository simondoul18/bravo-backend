<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Traits\ApiResponser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use App\Models\Employee;
use App\Models\Business;
use App\Models\Business_hour;
use App\Models\Employee_hour;
use App\Models\Booking;
use App\Models\Booking_service;
use App\Models\Business_service;
use App\Models\Booking_covid_point;
use App\Models\Transaction;
use App\Models\Req_a_quote;
use App\Models\Raq_service;
use App\Models\User;
use App\Models\Raq_offer;
use App\Models\Stripe_account;
use App\Models\Policy;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Stripe_payout;
use App\Models\Stripe_transfer;
use App\Models\Stripe_connect_account;
use App\Models\Stripe_payment;
use App\Services\StripeApis;
use App\Helpers\Helper;
use Carbon\Carbon;
use Haruncpi\LaravelIdGenerator\IdGenerator;
use App\Jobs\SendTwilioMessage;
use Illuminate\Support\Facades\Mail;
use App\Notifications\NewBooking;

class BookingController extends Controller
{
    use ApiResponser;
    public $bookingInfo;
    public $input;
    public $error;
	// private StripeApis $stripe;

	public function __construct(StripeApis $stripe)
    {
        $this->stripe = $stripe;
    }


    public function makeBooking(Request $request){

    	// Server Side Validation
    	$validator = Validator::make($request->all(), [
			// 'business_id' => 'required',
			'professional' => 'required',
			'services' => 'required',
			'time_slot' => 'required',
			'date' => 'required',
			'booking_source' => 'required'
		]);
		if ($validator->fails()) {
			$j_errors = $validator->errors();
			$errors = (array) json_decode($j_errors);
			$key = array_key_first($errors);
			return $this->error($errors[$key][0],"",422);
		}
		$input = $request->all();

		$business = '';
		if($request->has('business_id')){
			$business = Helper::getBusinessById($input['business_id']);
		}elseif($request->has('business')){
			$business = Helper::getBusinessBySlug($input['business']);
		}elseif($request->has('slug')){
			$business = Helper::getBusinessBySlug($input['slug']);
		}
		// Get Business Info
		if(empty($business)){
			return $this->error("Business Not found","",422);
		}

		// Prepare Booking Info
		$bookingInfo = [
			'booking_date' => date('Y-m-d',strtotime($input['date'])),
			'booking_start_time' => ($input['booking_source'] == 'walkin') ? date('H:i:s',strtotime($input['time_slot']['hh'].":".$input['time_slot']['mm']." ".$input['time_slot']['A'])) : date('H:i:s',strtotime($input['time_slot'])),
			'booking_price'=>0,
			'booking_duration'=>0,
			'booking_source'=>$input['booking_source'],
			'additional_note'=>$input['note'],
			'booking_type'=>2
		];

		// Deal ID
		if(!empty($input['deal'])){
			if (!empty($input['deal']['id'])) {
				$bookingInfo['deal_id'] = $input['deal']['id'];
			}else{
				return $this->error("Deal record is missing","",422);
			}
		}

		//Render location
    	if ($input['booking_source'] == 'walkin' && !empty($input['render_location'])) {
    		$bookingInfo['service_rendered'] = 1;
    	}elseif (($input['booking_source'] == 'raq' || $input['booking_source'] == 'online') && !empty($input['render_location'])) {
    		$bookingInfo['service_rendered'] = $input['render_location'];
    		if ($input['render_location'] == 2) {
    			$bookingInfo['rendered_address'] = $input['rendered_address'];
    			$bookingInfo['rendered_address_apt'] = $input['rendered_address_apt'];
    		}
    	}
		// Card Info
		if (!empty($input['card'])) {
    		$bookingInfo['payment_card_id'] = $input['card'];
		}elseif(empty($input['card']) && $input['booking_source'] == 'online' ){
			return $this->error("Card is required","",422);
		}




		// Get Provider Info
		$provider = Helper::getUserById($input['professional']['user_id']);
		if(empty($provider)){
			return $this->error("Invalid Provider","",422);
		}

		// Validate Time Slot
		if($input['booking_source'] == 'online'){
			# Validate It
		}


		// Get Services Data and Duration
		if (!empty($input['deal'])) {
			$services_data = $this->getServiceData($input['deal']['deal_services'],$input['booking_source']);
		}else{
			$services_data = $this->getServiceData($input['services'],$input['booking_source']);
		}
		$services = $services_data['bookingServices'];
		if(empty($services) || count($services) == 0){
			return $this->error("Services not found","",422);
		}
		if ($input['booking_source'] == 'raq') {
			$bookingInfo['booking_price'] = $input['price'];
			$bookingInfo['booking_duration'] = $input['duration'];
			$bookingInfo['booking_end_time'] = date('H:i:s',strtotime("+".$input['duration']." minutes", strtotime($input['time_slot'])));
		}elseif ($input['booking_source'] == 'walkin' || $input['booking_source'] == 'online') {
			$bookingInfo['booking_price'] = $services_data['bookingPrice'];
			$bookingInfo['booking_duration'] = $services_data['bookingDuration'];
			if ($input['booking_source'] == 'walkin') {
				$bookingInfo['booking_end_time'] = date('H:i:s',strtotime("+".$services_data['bookingDuration']." minutes", strtotime($input['time_slot']['hh'].":".$input['time_slot']['mm']." ".$input['time_slot']['A'])));
			}else{
				$bookingInfo['booking_end_time'] = date('H:i:s',strtotime("+".$services_data['bookingDuration']." minutes", strtotime($input['time_slot'])));
			}
		}

		// Apply Coupen IF Found


		// Get User Info
		if ($input['booking_source'] == 'walkin' || $input['booking_source'] == 'raq') {
			$userInfo = Helper::getUserByEmail($input['user']['email']);
			if(empty($userInfo)){
				$userInfo = $this->createUser();
			}
		}elseif($input['booking_source'] == 'online'){
			$userInfo = Auth::user();
		}else{
			return $this->error("Invalid Source","",422);
		}

		// Make Booking
		$booking_id = $this->bookingProcess($business,$provider,$userInfo,$bookingInfo);
		if (!empty($booking_id)) {
			// Add Booking Services
			$servicess = $this->addBookingServices($booking_id,$services);

			// Add Covid Points
			if (!empty($input['covid_points'])) {
				$cp = $this->addCovidPoints($booking_id,$input['covid_points']);
			}

			// Send Email and Notifications
			$provider->notify(new NewBooking($business,$userInfo,$provider,$bookingInfo,$services,'provider'));
			$userInfo->notify(new NewBooking($business,$userInfo,$provider,$bookingInfo,$services,'user'));
			if($provider->id != $business->user_id){
				$owner = User::find($business->user_id);
				$owner->notify(new NewBooking($business,$userInfo,$provider,$bookingInfo,$services,'owner'));
			}
			
			// $provider->notify(new NewBooking($userInfo,$bookingInfo));
			//Helper::sendMessage("Hello! How are you ?","+923453893330");
			return $this->success("Booking Done");
		}else{
			return $this->error("Something went wrong.");
		}
    }
    public function bookingProcess($business,$provider,$user,$bookingInfo){
    	// Add Ondaq Fee
		if(empty($business->free_trial) && empty($business->payment_method))
    	{
			$ondaqFee =  Helper::calculateTaxAmount($bookingInfo["booking_price"],"paye");
    	}
    	// $color = Helper::bookingColors(0);

    	$data = [
    		// User Info
    		'user_id' => $user->id,
			'tracking_id' => IdGenerator::generate(['table' => 'bookings','field'=>'tracking_id','length'=>9,'prefix'=>'BK-']),
    		'user_firstname' => !empty($user->first_name) ? $user->first_name : null,
			'user_lastname' => !empty($user->last_name) ? $user->last_name : null,
			'user_email' => !empty($user->email ) ? $user->email  : null,
			'user_phone' => !empty($user->phone) ? $user->phone : null,
			'user_address' => !empty($user->address) ? $user->address : null,
			'user_profile_picture' => !empty($user->picture) ? $user->picture : null,
			//business info
			'business_id' => $business->id,
			'business_owner_id' => !empty($business->user_id) ? $business->user_id : null,
			'business_name' => !empty($business->title) ? $business->title : null,
			'business_image' => !empty($business->profile_pic) ? $business->profile_pic : null,
			'business_location' => !empty($business->address) ? $business->address : null,
			'business_phone' => !empty($business->phone) ? $business->phone : null,
			//provider
			'provider_id' => $provider->id,
			'provider_firstname' => !empty($provider->first_name) ? $provider->first_name : null,
			'provider_lastname' => !empty($provider->last_name) ? $provider->last_name : null,
			'provider_email' => !empty($provider->email) ? $provider->email : null,
			'provider_phone' => !empty($provider->phone) ? $provider->phone: null,
			'provider_address' => !empty($provider->address) ? $provider->address : null,
			'provider_image' => !empty($provider->picture) ? $provider->picture : null,
			//Others
			'color'=> !empty($color) ? $color['border'].','.$color['background'] : null,
			'ondaq_fee' => !empty($ondaqFee) ? $ondaqFee : 0,
			'created_at' => date('Y-m-d H:i:s')
    	];
    	// Calculate Ondaq Fee

    	$ins_arr = array_merge($bookingInfo,$data);
		//return $ins_arr;
    	$bookingId  = Booking::create($ins_arr)->id;
    	if (!empty($bookingId)) {
    		return $bookingId;
    	}else{
    		return false;
    	}
    }
    public function getServiceData($services,$source)
    {
    	$bookingServices = [];
    	$bookingPrice = 0;
		$bookingDuration = 0;
    	if(!empty($services) && count($services) > 0){
    		foreach ($services as $key => $serv) {
				$is_selecte = true;
				if ($source == 'walkin' && $serv['selected'] == false) {
					$is_selecte = false;
				}

				if ($is_selecte === true) {
					$data = Business_service::with('service')->where('id',$serv['business_service_id'])->first();
					$bookingServices[] = $data;
					$bookingDuration += $data->duration;
					$bookingPrice += $data->cost;
				}
    		}
    	}
    	return [
    		'bookingServices'=>$bookingServices,
    		'bookingPrice' => $bookingPrice,
    		'bookingDuration' => $bookingDuration
    	];
    }
	public function addBookingServices($bookingId,$services)
	{
		foreach ($services as $key => $serv) {
			unset($serv->business_id);
			unset($serv->default_service);
			$serv->created_at = date('Y-m-d H:i:s');
			$serv->updated_at = date('Y-m-d H:i:s');
			unset($serv->status);

			$serv->booking_id = $bookingId;
			$serv->title = $serv->service->title;
			$serv->business_service_id = $serv->id;
			unset($serv->service);
			unset($serv->id);
			$data = json_decode(json_encode($serv), true);
			//return $data;
			Booking_service::insert($data);
		}
	}
    public function addCovidPoints($bookingId,$covid_points='')
    {
    	if(!empty($covid_points))
		{
	    	foreach ($covid_points as $key => $cp) {
				$covidPoints = [];
	    		$covidPoints['booking_id'] = $bookingId;
		    	$covidPoints['covid_point_id'] = !empty($cp['id']) ? $cp['id'] : 0;
		    	$covidPoints['point'] = !empty($cp['points']) ? $cp['points'] : null;
		    	$covidPoints['answer'] = !empty($cp['value']) ? $cp['value'] : null;
		    	$covidPoints['created_at'] = date('Y-m-d H:i:s');
				Booking_covid_point::insert($covidPoints);
	    	}

    	}
    }



	// Add Walk In
    // public function addWalkInBooking(Request $request){

    // 	// Server Side Validation
    // 	$validator = Validator::make($request->all(), [
	// 		'business_id' => 'required',
	// 		'customer' => 'required',
	// 		'professional' => 'required',
	// 		'services' => 'required',
	// 		'time_slot' => 'required',
	// 		'date' => 'required',
	// 		'booking_type' => 'required',
	// 		'booking_source' => 'required'
	// 	]);
	// 	if ($validator->fails()) {
	// 		$j_errors = $validator->errors();
	// 		$errors = (array) json_decode($j_errors);
	// 		$key = array_key_first($errors);
	// 		return $this->error($errors[$key][0],"",422);
	// 	}
	// 	$input = $request->all();


	// 	// Prepare Booking Info
	// 	$bookingInfo = [
	// 		'booking_date' => date('Y-m-d',strtotime($input['date'])),
	// 		'booking_start_time' => date('H:i:s',strtotime($input['time_slot']['hh'].":".$input['time_slot']['mm']." ".$input['time_slot']['A'])),
	// 		'booking_price'=>0,
	// 		'booking_duration'=>0,
	// 		'booking_source'=>$input['booking_source'],
	// 		'additional_note'=>$input['note'],
	// 		'booking_type'=>2
	// 	];
    // 	if ($input['booking_source'] == 'walkin' && !empty($input['render_location'])) {
    // 		$bookingInfo['service_rendered'] = 1;
    // 	}elseif (($input['booking_source'] == 'raq' || $input['booking_source'] == 'online') && !empty($input['render_location'])) {
    // 		$bookingInfo['service_rendered'] = $input['render_location'];
    // 		if ($input['render_location'] == 2) {
    // 			$bookingInfo['rendered_address'] = $input['rendered_address'];
    // 			$bookingInfo['rendered_address_apt'] = $input['rendered_address_apt'];
    // 		}
    // 	}
	// 	if (!empty($input['card'])) {
    // 		$bookingInfo['payment_card_id'] = $input['card'];
	// 	}elseif(empty($input['card']) && $input['booking_source'] == 'online' ){
	// 		return $this->error("Card is required","",422);
	// 	}



	// 	// Get Business Info
	// 	$business = Helper::getBusinessById($input['business_id']);
	// 	if(empty($business)){
	// 		return $this->error("Business Not found","",422);
	// 	}


	// 	// Get Provider Info
	// 	$provider = Helper::getUserById($input['professional']['user_id']);
	// 	if(empty($provider)){
	// 		return $this->error("Invalid Provider","",422);
	// 	}

	// 	// Validate Time Slot
	// 	if($input['booking_source'] == 'online'){
	// 		# Validate It
	// 	}


	// 	// Get Services Data and Duration
	// 	$services_data = $this->getServiceData($input['services']);
	// 	$services = $services_data['bookingServices'];
	// 	if(empty($services) || count($services) == 0){
	// 		return $this->error("Services not found","",422);
	// 	}
	// 	if ($input['booking_source'] == 'raq') {
	// 		$bookingInfo['booking_price'] = $input['price'];
	// 		$bookingInfo['booking_duration'] = $input['duration'];
	// 		$bookingInfo['booking_end_time'] = date('H:i:s',strtotime("+".$input['duration']." minutes", strtotime($input['time_slot'])));
	// 	}elseif ($input['booking_source'] == 'walkin' || $input['booking_source'] == 'online') {
	// 		$bookingInfo['booking_price'] = $services_data['bookingPrice'];
	// 		$bookingInfo['booking_duration'] = $services_data['bookingDuration'];
	// 		$bookingInfo['booking_end_time'] = date('H:i:s',strtotime("+".$services_data['bookingDuration']." minutes", strtotime($input['time_slot'])));
	// 	}

	// 	// Apply Coupen IF Found


	// 	// Get User Info
	// 	if ($input['booking_source'] == 'walkin' || $input['booking_source'] == 'raq') {
	// 		$userInfo = Helper::getUserByEmail($input['user']['email']);
	// 		if(empty($userInfo)){
	// 			$userInfo = $this->createUser();
	// 		}
	// 	}elseif($input['booking_source'] == 'online'){
	// 		$userInfo = Auth::user();
	// 	}else{
	// 		return $this->error("Invalid Source","",422);
	// 	}


	// 	// Make Booking
	// 	$booking_id = $this->bookingProcess($business,$provider,$userInfo,$bookingInfo);
	// 	if (!empty($booking_id)) {
	// 		// Add Booking Services
	// 		$servicess = $this->addBookingServices($booking_id,$services);

	// 		// Add Covid Points
	// 		if (!empty($input['covid_points'])) {
	// 			$cp = $this->addCovidPoints($booking_id,$input['covid_points']);
	// 		}

	// 		// Send Email and Notifications

	// 		return $this->success("Booking Done");
	// 	}else{
	// 		return $this->error("Something went wrong.");
	// 	}
    // }




    function addWalkIn_old(Request $request){
    	//return $request;
    	$validator = Validator::make($request->all(), [
			'business_id' => 'required',
			'professional' => 'required',
			//'render_location' => 'required',
			'services' => 'required',
			//'time_slot' => 'required'
		]);
		if ($validator->fails()) {
			$j_errors = $validator->errors();
			$errors = (array) json_decode($j_errors);
			$key = array_key_first($errors);
			return $this->error($errors[$key][0],"",422);
		}
		$this->input = $request->all();

		//get user data
		$this->input['userInfo'] = $this->input['customer'];
		/*if (Auth::check()) {
			$this->input['userInfo'] = Auth::user();
		}else{
			return $this->error("Business Not found","",422);
		}*/

		//check if business exist get detail of the business
		$businessInfo = Business::findOrFail($this->input['business_id']);
		if(empty($businessInfo)){
			return $this->error("Business Not found","",422);
		}

		$this->input['businessInfo'] = $businessInfo;

		$this->input['professionalInfo'] = $this->input['professional'];

		//prepare data for booking
		$this->prepareBookingData();

		//Save data in db
		$bookingId = $this->createBooking();
		if(!$bookingId){
			return $this->error("Something Went Wrong","",422);
		}

		//Covid points
		if(!empty($input['covid_points'])){
			$this->saveCovidPoints($bookingId);
		}

		//stripe Payment
		// if(!empty($this->input['card']) && $this->input['booking_source'] == 'online' )
		// {
		// 	$this->stripeCaptureAmount();
		// }

		return $this->success("Booking Done Successfully");
    }


	// Get Bookings
	public function getBookings(Request $request)
	{
		$input = $request->all();
	 	$query = Booking::with('BoookingServices', 'BoookingCovidPoints');

	 	//if type is not empty get Queue or booking
	 	if(!empty($input['type'])){
        	$query->where('bookings.booking_type', '=', $input['type']);
        }

       	//if business id is set return business booking else return client bookings
	 	if(!empty($input['business_id'])){
        	$query->where('bookings.business_id', '=', $input['business_id']);
        }else{
        	$query->where('bookings.user_id', '=', Auth::id());
        }

        // get professional bookings
        if(!empty($input['employee_id'])){
        	$query->where('bookings.provider_id', '=', $input['employee_id']);
        }

	 	//Duration
	 	if (!empty($input['duration'])) {
            $today = date('Y-m-d');
            if($input['duration'] == 'today'){
                $query->whereDate('bookings.booking_date', '=', $today);
            }else if($input['duration'] == 'past'){
                $query->whereDate('bookings.booking_date', '<', $today);
            }else if($input['duration'] == 'upcoming'){
                $query->whereDate('bookings.booking_date', '>', $today);
            }else if($input['duration'] == 'specific'){
                $query->whereDate('bookings.booking_date', '=', $input['date'] );
            }else if($input['duration'] == 'date_range'){
                $query->whereBetween('bookings.booking_date', [$input['from'],$input['to']]);
            }
        }

        $bookings = $query->get();
        return $this->success($bookings);
	}
    public function bookingCounter($business_id='')
    {
		if(empty($business_id)){
            return $this->error('Business not found');
        }

    	$counter['today_queues'] = Booking::where('business_id',$business_id)->where('booking_type',1)->where('booking_date',date('Y-m-d'))->count();
    	$counter['today_appointments'] = Booking::where('business_id',$business_id)->where('booking_type',2)->where('booking_date',date('Y-m-d'))->count();
    	$counter['past_queues'] =  Booking::where('business_id',$business_id)->where('booking_type',1)->where('booking_date','<',date('Y-m-d'))->count();
    	$counter['past_appointments'] = Booking::where('business_id',$business_id)->where('booking_type',2)->where('booking_date','<',date('Y-m-d'))->count();
    	$counter['future_appointments'] = Booking::where('business_id',$business_id)->where('booking_type',2)->where('booking_date','>',date('Y-m-d'))->count();

    	return $this->success($counter);
    }

    public function calendarBookings(Request $request)
    {
		$validator = Validator::make($request->all(), [
			'business_id' => 'required',
			'provider' => 'required'
		]);
		if ($validator->fails()) {
			return [];
		}
		$input = $request->all();
		$start =  date("Y-m-d",strtotime($input['start']));
		$end =  date("Y-m-d",strtotime($input['end']));
        $q = Booking::with('BoookingServices:booking_id,title')
		->where('business_id',$input['business_id'])
		->whereDate('booking_date','>=', $start)
		->whereDate('booking_date','<',$end)
		->where('booking_type',2);
        if($input['provider'] != 'all'){
			$q->where('provider_id',$input['provider']);
		}
		$bookingsInfo = $q->get();
        $nc= [];
        foreach($bookingsInfo as $key => $bookings){
        	$services = '';
        	foreach ($bookings->BoookingServices as $key => $serv) {
        		if ($key > 0) {
        			$services .= ", ";
        		}
        		$services .= $serv->title;
        	}
			$colors = explode(",",$bookings->color);
        	$nc[] = [
        		'id'=> $bookings->id,
        		//'title' => '<b>'.$bookings->user_firstname.' '.$bookings->user_lastname.'</b><br>'.rtrim($services,", ").'<br>'.date('g:ia',strtotime($bookings->booking_start_time)).' - '.date('g:ia',strtotime($bookings->booking_end_time)),
        		'title'=> '<b>'.$bookings->user_firstname.' '.$bookings->user_lastname.'</b>',
        		'start'=>$bookings->booking_date.' '.$bookings->booking_start_time,
        		'end' => $bookings->booking_date.' '.$bookings->booking_end_time,
        		'backgroundColor' => !empty($colors[1])?$colors[1]:"",
        		'borderColor' => !empty($colors[0])?$colors[0]:"",
        		'textColor' => '#333',
        		'timeStr' => date('g:ia',strtotime($bookings->booking_start_time)).' - '.date('g:ia',strtotime($bookings->booking_end_time)),
        		'description' => rtrim($services,", "),
        		'services' => rtrim($services,", "),
        		'status' => $bookings->booking_status
        	];
        }
        return response($nc);
        //$this->success($calendarArr);

    }

	public function bookingDetail($id)
	{
		$Bookingdetail = Booking::with("provider:id,name,picture,email")->with('BoookingServices')->with('BoookingCovidPoints')->with('user:id,name,picture,email')->with('business:id,title,title_slug,profile_pic')->where('id',$id)->first();
		return $this->success($Bookingdetail);
	}
	public function rescheduleBooking(Request $request)
	{
		$validator = Validator::make($request->all(), [
			'date' => 'required',
			'start_time' => 'required',
			'booking_id' => 'required',
		]);
		if ($validator->fails()) {
			$j_errors = $validator->errors();
			$errors = (array) json_decode($j_errors);
			$key = array_key_first($errors);
			return $this->error($errors[$key][0],"",422);
		}

		$params = $request->all();
		$booking = Booking::select("booking_duration")->where('id',$params['booking_id'])->first();
		$timeStr = $params['start_time']['hh'].":".$params['start_time']['mm']." ".$params['start_time']['A'];
		$resp = Booking::where('id',$params['booking_id'])->update([
			'booking_date' => date('Y-m-d',strtotime($params['date'])),
			'booking_start_time' => date('H:i:s',strtotime($timeStr)),
			'booking_end_time' => date('H:i:s',strtotime("+".$booking->booking_duration." minutes", strtotime($timeStr)))
		]);
		if ($resp) {
			return $this->success('',"Booking successfully reschedule.");
		}else{
			return $this->error("Something went wrong.");
		}
	}










    // function bookAppointment(Request $request){
	// 	//return $this->error("Business Not found","",422);
    // 	$validator = Validator::make($request->all(), [
	// 		'business_id' => 'required',
	// 		'professional' => 'required',
	// 		'render_location' => 'required',
	// 		'services' => 'required',
	// 		'time_slot' => 'required',
	// 		'booking_source' => 'required'
	// 	]);
	// 	if ($validator->fails()) {
	// 		$j_errors = $validator->errors();
	// 		$errors = (array) json_decode($j_errors);
	// 		$key = array_key_first($errors);
	// 		return $this->error($errors[$key][0],"",422);
	// 	}
	// 	// print_r($this->input);
	// 	$this->input = $request->all();


	// 	//get user data
	// 	if (Auth::check()) {
	// 		$this->input['userInfo'] = Auth::user();
	// 	}else{
	// 		return $this->error("Business Not found","",422);
	// 	}

	// 	//check if business exist get detail of the business
	// 	$businessInfo = Business::findOrFail($this->input['business_id']);
	// 	if(empty($businessInfo)){
	// 		return $this->error("Business Not found","",422);
	// 	}

	// 	$this->input['businessInfo'] = $businessInfo;

	// 	$this->input['professionalInfo'] = $this->input['professional'];


	// 	//prepare data for booking
	// 	$this->prepareBookingData();

	// 	//Save data in db
	// 	$bookingId = $this->createBooking();

	// 	if(!$bookingId){
	// 		return $this->error("Something Went Wrong","",422);
	// 	}


	// 	//Covid points
	// 	if(!empty($this->input['covid_points'])){
	// 		$this->saveCovidPoints($bookingId);
	// 	}

	// 	//stripe Payment
	// 	// if(!empty($this->input['card']) && $this->input['booking_source'] == 'online' )
	// 	// {
	// 	// 	$this->stripeCaptureAmount();
	// 	// }

	// 	return $this->success("Booking Done Successfully");


    // }
    // public function saveCovidPoints($bookingId = 0)
    // {
    // 	if(!empty($this->input['covid_points']) && !empty($bookingId) ){
	//     	$covidPoints = [];
	//     	foreach ($this->input['covid_points'] as $key => $value) {
	//     		$covidPoints[$key]['booking_id'] = $bookingId;
	// 	    	$covidPoints[$key]['business_id'] = !empty($value['business_id']) ? $value['business_id'] : 0;
	// 	    	$covidPoints[$key]['covid_point_id'] = !empty($value['id'] ) ? $value['id']  : 0;
	// 	    	$covidPoints[$key]['points'] = !empty($value['points']) ? $value['points'] : null;
	// 	    	$covidPoints[$key]['point_for'] = !empty($value['points_for']) ? $value['points_for'] : 0;
	// 	    	$covidPoints[$key]['user_id'] = !empty($value['user_id']) ? $value['user_id'] : 0;
	//     	}
	//     	Booking_covid_point::insert($covidPoints);
    // 	}



    // }
    // public function prepareBookingData()
    // {
    // 	$this->bookingInfo['parent'] = 0;
    // 	$this->bookingInfo['booking_type'] = !empty($this->input['booking_type']) ? $this->input['booking_type'] : 1;

    // 	$this->bookingInfo['user_id'] = 1;
    // 	$this->bookingInfo['business_id'] = !empty($this->input['businessInfo']->id) ? $this->input['businessInfo']->id : null;
    // 	$this->bookingInfo['provider_id'] = !empty($this->input['professionalInfo']['employee_id']) ? $this->input['professionalInfo']['employee_id'] : null;

    // 	//Preapre data for User
    // 	$this->prepareDenormalizeData();


    // 	//Services calculation
    // 	$this->prepareServiceData();


    // 	if(empty($this->input['businessInfo']->free_trial) && empty($this->input['businessInfo']->payment_method))
    // 	{
    // 		//Need to fetch from DB
    // 		$ondaqFee = 5.00;
    // 	}

    // 	$this->bookingInfo['ondaq_fee'] = !empty($ondaqFee) ? $ondaqFee : 0;

    // 	if(!empty($this->input['render_location'])){
    // 		$this->bookingInfo['rendered_address'] = !empty($this->input['rendered_address']) ? $this->input['rendered_address'] : null;
    // 	}
    // 	$this->bookingInfo['service_rendered'] = !empty($this->input['render_location']) ? $this->input['render_location'] : null;
    // 	$this->bookingInfo['booking_date'] = !empty($this->input['date']) ? date("Y-m-d", strtotime($this->input['date'])) : null;
    // 	$this->bookingInfo['booking_start_time'] = date("H:i", strtotime($this->input['time_slot']));
    // 	$this->bookingInfo['booking_end_time'] =  date("H:i", strtotime('+'.$this->bookingInfo['service_time'].' minutes', strtotime($this->input['time_slot'])));
    // 	$this->bookingInfo['booking_source'] = !empty($this->input['booking_source']) ? $this->input['booking_source'] : 'online';
    // 	$this->bookingInfo['payment_card_id'] = !empty($this->input['card']) ? $this->input['card'] : null;


    // }
    // public function prepareDenormalizeData($value='')
    // {
    // 	//user info
    // 	$this->bookingInfo['user_firstname'] = !empty($this->input['userInfo']->first_name) ? $this->input['userInfo']->first_name : null;
    // 	$this->bookingInfo['user_lastname'] = !empty($this->input['userInfo']->last_name) ? $this->input['userInfo']->last_name : null;
    // 	$this->bookingInfo['user_email'] = !empty($this->input['userInfo']->email ) ? $this->input['userInfo']->email  : null;
    // 	$this->bookingInfo['user_phone'] = !empty($this->input['userInfo']->phone) ? $this->input['userInfo']->phone : null;
    // 	$this->bookingInfo['user_address'] = !empty($this->input['userInfo']->address) ? $this->input['userInfo']->address : null;
    // 	$this->bookingInfo['user_profile_picture'] = !empty($this->input['userInfo']->picture) ? $this->input['userInfo']->picture : null;

    // 	//business info
    // 	$this->bookingInfo['business_name'] = !empty($this->input['businessInfo']->title) ? $this->input['businessInfo']->title : null;
    // 	$this->bookingInfo['business_image'] = !empty($this->input['businessInfo']->profile_pic) ? $this->input['businessInfo']->profile_pic : null;
    // 	$this->bookingInfo['business_location'] = !empty($this->input['businessInfo']->address) ? $this->input['businessInfo']->address : null;
    // 	$this->bookingInfo['business_phone'] = !empty($this->input['businessInfo']->phone) ? $this->input['businessInfo']->phone : null;

    // 	//provider
    // 	$this->bookingInfo['provider_firstname'] = !empty($this->input['professionalInfo']->first_name) ? $this->input['professionalInfo']->first_name : null;
    // 	$this->bookingInfo['provider_lastname'] = !empty($this->input['professionalInfo']->last_name) ? $this->input['professionalInfo']->last_name : null;
    // 	$this->bookingInfo['provider_email'] = !empty($this->input['professionalInfo']->email) ? $this->input['professionalInfo']->email : null;
    // 	$this->bookingInfo['provider_phone'] = !empty($this->input['professionalInfo']->phone) ? $this->input['professionalInfo']->phone: null;
    // 	$this->bookingInfo['provider_address'] = !empty($this->input['professionalInfo']->address) ? $this->input['professionalInfo']->address : null;
    // 	$this->bookingInfo['provider_image'] = !empty($this->input['professionalInfo']->picture) ? $this->input['professionalInfo']->picture : null;

    // }
    // public function prepareServiceData($value='')
    // {
    // 	if(!empty($this->input['services'])){
    // 		$serviceDuration = 0;
    // 		$servicePrice = 0;
    // 		$serviceTax = 0;
    // 		$serviceDiscount = 0;

    // 		foreach ($this->input['services'] as $key => $value) {
    // 			$serviceDuration += $value['duration'];
    // 			$servicePrice += $value['cost'];
    // 			$serviceTax += $value['tax'];
    // 			//$serviceDiscount += $value['discount'];
    // 		}

    // 		$this->bookingInfo['service_time'] = !empty($serviceDuration) ? $serviceDuration : 0;
	//     	$this->bookingInfo['service_price'] = !empty($servicePrice) ? $servicePrice : 0;
	//     	$this->bookingInfo['service_tax'] = !empty($serviceTax) ? $serviceTax : 0;
	//     	//$this->bookingInfo['service_discount'] = !empty($serviceDiscount) ? $serviceDiscount : 0;

	//     	$serviceFinalAmount = $servicePrice - ($serviceTax + $serviceDiscount);
	//     	$this->bookingInfo['service_final_price'] = !empty($serviceFinalAmount) ? $serviceFinalAmount : 0;

    // 	}

    // }
    // public function createBooking()
    // {

    // 	if(!empty($this->bookingInfo))
    // 	{
    // 		$bookingId  = Booking::insertGetId($this->bookingInfo);
    // 	}

    // 	if(!empty($bookingId)){
    // 		//save Booking Services
    // 		$this->saveBookingServices($bookingId);
    // 	}
    // 	return $bookingId;
    // }
    // public function saveBookingServices($bookingId=0)
    // {
    // 	if(!empty($this->input['services']) && !empty($bookingId) ){

    // 		$bookingServices = [];
    // 		foreach ($this->input['services'] as $key => $value) {
    // 			$bookingServices[$key]['booking_id'] = $bookingId;
    // 			$bookingServices[$key]['service_id'] = $value['id'];
    // 			$bookingServices[$key]['title'] = $value['title'];
    // 			$bookingServices[$key]['duration'] = $value['duration'];
    // 			$bookingServices[$key]['cost'] = $value['cost'];
    // 			$bookingServices[$key]['tax'] = $value['tax'];

    // 		}
    // 		Booking_service::insert($bookingServices);
    // 	}

    // }



	 /**
	 * Store a newly created booking.
	 *
	 * @param  \Illuminate\Http\Request  $request
	 * @return \Illuminate\Http\Response
	*/





    // QUEUE
    function joinQueue(Request $request){
    	if (Auth::check()) {
			$validator = Validator::make($request->all(), [
				'services' => 'required',
				//'employee' => 'required',
				//'slug' => 'required',
				'booking_source' => 'required'
			]);
			if ($validator->fails()) {
				$j_errors = $validator->errors();
				$errors = (array) json_decode($j_errors);
				$key = array_key_first($errors);
				return $this->error($errors[$key][0],"",422);
			}

			$params = $request->all();

			// Business Info
			$business = '';
			if (!empty($params['slug'])) {
				$business = Helper::getBusinessBySlug($params['slug']);
			}elseif (!empty($params['business_id'])) {
				$business = Helper::getBusinessById($params['business_id']);
			}
			if(empty($business)){
				return $this->error("Business Not Found.","",422);
			}

			// Get Provider Info
			$provider = '';
			if (!empty($params['employee'])) {
				$employee_id = $params['employee']['id'];
				$provider = Helper::getUserById($params['employee']['user']['id']);
			}elseif (!empty($params['professional'])) {
				$employee_id = $params['professional']['id'];
				$provider = Helper::getUserById($params['professional']['user']['id']);
			}
			if(empty($provider)){
				return $this->error("Invalid Provider.","",422);
			}

			// Get Services Data and Duration
			$services_data = $this->getServiceData($params['services'],$params['booking_source']);
			$services = $services_data['bookingServices'];
			if(empty($services) || count($services) == 0){
				return $this->error("Services not found","",422);
			}


			// Prepare Booking Data
			$bookingInfo = [
				'booking_source'=>$params['booking_source'],
				'additional_note'=>$params['note'],
				'booking_type'=>1,
				'booking_price' => $services_data['bookingPrice'],
				'booking_duration' => $services_data['bookingDuration']
			];

			if (!empty($params['card'])) {
	    		$bookingInfo['payment_card_id'] = $params['card'];
			}elseif(empty($params['card']) && $params['booking_source'] == 'online' ){
				return $this->error("Card is required","",422);
			}


			// Get  Time
			$today = Helper::get_day(date("Y-m-d"));
			$tomorrow = $today+1;
			if($today == 7){
				$tomorrow = 1;
			}
			// Check business open status
			$businessOpen =Business_hour::where('business_id',$business->id)->where('isOpen',1)->where(function  ($q) use ($today,$tomorrow) {
				$q->where('day', $today)->orWhere('day', $tomorrow);
			})->count();

			if($businessOpen > 0){
				// Get Employee Schedule Detail
				$qry = Employee_hour::where('business_id',$business->id)->where('employee_id',$employee_id)->where('isOpen',1);
				$employeeSchedule =$qry->where('day', $today)->first();
				if (empty($employeeSchedule)) {
					$employeeSchedule =$qry->where('day', $tomorrow)->first();
				}
				if(!empty($employeeSchedule) && !empty($employeeSchedule->isOpen) ){
					$service_duration = $services_data['bookingDuration'];
					// foreach ($params['services'] as $key => $value) {
					// 	$service_duration += $value['duration'];
					// }


					if($today == $employeeSchedule->day ){
						$bookingDate = date("Y-m-d");
					}elseif($tomorrow == $employeeSchedule->day){
						$bookingDate = date("Y-m-d", strtotime("+1 day"));
					}

					$bookingTypeArr = [1,2];
					$booking_info = Booking::select('id', 'booking_end_time')->where('provider_id',$provider->id)->where('business_id',$business->id)->where('booking_type',1)->where('booking_date',$bookingDate)->orderBy('id', 'DESC')->first();



					//lunch start and end time
					$start_lunch = $employeeSchedule->breakStart;
					$end_lunch = $employeeSchedule->breakEnd;
					//calculate  employe lunch ending time and service duration
					$lunch_end_time = $this->addServiceDurationToTime($service_duration,$end_lunch);

					//calculate  employe starting  time and service duration
					$employeeStartingTime = $this->addServiceDurationToTime($service_duration,$employeeSchedule->start_time);

					//calculate current time  and service duration
					$currentTimeWithServiceDuration = $this->addServiceDurationToTime($service_duration,date("H:i:s"));
					//return $currentTimeWithServiceDuration;

					if (!empty($booking_info)) {
						if(empty($start_lunch) || empty($end_lunch) ){
							$start_time = $booking_info['booking_end_time'];
						}else{
							$booking_end_time = $this->addServiceDurationToTime($service_duration,$booking_info->booking_end_time);

							if($booking_info['booking_end_time']  < $start_lunch){
								if($start_lunch <= $booking_end_time && $end_lunch > $booking_end_time){
									if($lunch_end_time < $employeeSchedule->end_time ){
										$start_time = $end_lunch;
									}else{
										return $this->error("Employee is not available.1","",422);
									}
								}else{
									if($booking_end_time < $start_lunch ){
										if(date('H:i:s') > $booking_info['booking_end_time'] ){
											$start_time = date('H:i:s');
										}else{
											$start_time = $booking_info['booking_end_time'];
										}

									}else{
										return $this->error("Employee is not available.2","",422);
									}
								}
							}elseif($booking_info['booking_end_time'] > $end_lunch){
								if($lunch_end_time <= $employeeSchedule->end_time ){
									if(date('H:i:s') > $booking_info['booking_end_time'] ){
										$start_time = date('H:i:s');
									}else{
										$start_time = $booking_info['booking_end_time'];
									}
								}else{
									return $this->error("Employee is not available.3","",422);
								}
							}else{
								return $this->error("Employee is not available.4","",422);
							}
						}

					 	$tokenNumber =  1;

					}elseif (date("Y-m-d H:i:s") < date("Y-m-d H:i:s",strtotime($employeeSchedule->start_time)) ) {

						if(empty($start_lunch) || empty($end_lunch) ){
							$start_time = $employeeSchedule->start_time;
						}else{
							if($start_lunch <= $employeeSchedule->start_time && $end_lunch > $employeeSchedule->start_time){
								if($lunch_end_time < $employeeSchedule->end_time ){
									$start_time = $end_lunch;
								}else{
									return $this->error("Employee is not available.5","",422);
								}
							}elseif($employeeSchedule->start_time < $start_lunch){
								if($start_lunch <= $employeeStartingTime && $end_lunch > $employeeStartingTime){
									if($lunch_end_time <= $employeeSchedule->end_time ){
										$start_time = $end_lunch;
									}else{
										return $this->error("Employee is not available.6","",422);
									}
								}else{
									$start_time = $employeeSchedule->start_time;
								}

							}else{
								return $this->error("Employee is not available.7","",422);
							}
						}
						$tokenNumber = 1;

					}else{
						if(date("H:i:s") < $employeeSchedule->end_time && $currentTimeWithServiceDuration < $employeeSchedule->end_time  ){

							if(empty($start_lunch) || empty($end_lunch) ){
								$start_time = date("H:i:s");
							}else{
								if($start_lunch <= date("H:i:s") && $end_lunch > date("H:i:s")){
									if($lunch_end_time < $employeeSchedule->end_time ){
										$start_time = $end_lunch;
									}else{
										return $this->error("Employee is not available.8","",422);
									}

								}elseif(date("H:i:s") < $start_lunch ){
									if($currentTimeWithServiceDuration < $start_lunch ){
										$start_time = date("H:i:s");
									}else{
										if($lunch_end_time < $employeeSchedule->end_time ){
											$start_time = $end_lunch;
										}else{
											return $this->error("Employee is not available.9","",422);
										}
									}

								}elseif(date("H:i:s") > $end_lunch){
									if($currentTimeWithServiceDuration < $employeeSchedule->end_time ){
										$start_time = date("H:i:s");
									}else{
										return $this->error("Employee is not available.10","",422);
									}
								}else{
									return $this->error("Employee is not available.11","",422);
								}

							}

						}else{
							return $this->error("Employee is not available.12","",422);
						}
						$tokenNumber =  1;
					}
				}else{
					return $this->error("Employee is not available.","",422);
				}
			}else{
				return $this->error("Business is closed.".date('w'),"",422);
			}


			// Get User Info
			if ($params['booking_source'] == 'walkin') {
				$userInfo = Helper::getUserByEmail($params['user']['email']);
				if(empty($userInfo)){
					$userInfo = $this->createUser();
				}
			}elseif($params['booking_source'] == 'online'){
				$userInfo = Auth::user();
			}else{
				return $this->error("Invalid Source","",422);
			}


			// Prepare Booking Data
			$bookingInfo['booking_date'] = date('Y-m-d',strtotime($bookingDate));
			$bookingInfo['booking_start_time'] = date("H:i", strtotime('+5 minutes', strtotime($start_time)));
			$bookingInfo['booking_end_time'] = date("H:i", strtotime('+'.($service_duration+5).' minutes', strtotime($start_time)));


			// Make Booking
			$booking_id = $this->bookingProcess($business,$provider,$userInfo,$bookingInfo);
			if (!empty($booking_id)) {
				// Add Booking Services
				$servicess = $this->addBookingServices($booking_id,$services);

				// Add Covid Points
				if (!empty($input['covid_points'])) {
					$this->addCovidPoints($booking_id,$input['covid_points']);
				}

				// Send Email and Notifications

				return $this->success("Queue Done");
			}else{
				return $this->error("Something went wrong.");
			}
		}else{
			return $this->notLogin();
		}
	}
	function addServiceDurationToTime($duration,$time){
		$res = strtotime("+".$duration." minutes", strtotime($time));
		return date('H:i:s', $res);
	}
	function getQueuesByEmployee(Request $request){
		$validator = Validator::make($request->all(), [
			'user_id' => 'required',
			'provider_id' => 'required',
			'slug'=> 'required'
		]);
		if ($validator->fails()) {
			$j_errors = $validator->errors();
			$errors = (array) json_decode($j_errors);
			$key = array_key_first($errors);
			return $this->error($errors[$key][0],"",422);
		}
		$params = $request->all();

		$business = Helper::getBusinessBySlug($params['slug']);
		$booking_info = Booking::where('provider_id',$params['user_id'])->where('business_id',$business->id)->where(function($query){$query->where('booking_status',0)->orWhere('booking_status',1);})->where('booking_type',1)->whereDate('booking_date', Carbon::today())->get();
		if(!empty($booking_info)){
			foreach ($booking_info as $key => $value) {
				$booking_info[$key]->booking_start_time = date("h:i A",strtotime($value->booking_start_time));
			}
			return $this->success($booking_info);
		}else{
			return $this->success([]);
		}
	}
	public function updateBookingStatus(Request $request)
	{
		$validator = Validator::make($request->all(), [
			'booking_id' => 'required',
			'status' => 'required'
		]);
		if ($validator->fails()) {
			$j_errors = $validator->errors();
			$errors = (array) json_decode($j_errors);
			$key = array_key_first($errors);
			return $this->error($errors[$key][0],"",422);
		}
		$this->input = $request->all();
		$this->bookingInfo = Booking::where('id',$this->input['booking_id'])->first();
		$color = [];
		$message = "Updated Successfully";
		if($this->input['status'] == 'start'){
			if($this->bookingInfo['booking_status'] == 0){
				$transactionId = 0;
				$btransactionId = 0;
				if($this->bookingInfo['booking_source'] == 'online')
				{
					//Deduct Payment
					$amount = 0;
					$payment_card_id = 0;
					if(!empty($this->bookingInfo['booking_price']))
					{
						$amount = $this->bookingInfo['booking_price'];
					}
					if(!empty($this->bookingInfo['payment_card_id']))
					{
						$payment_card_id = $this->bookingInfo['payment_card_id'];
					}
					$transactionDetails  = $this->bookingPayment();
					if($transactionDetails['status'] == false){
						//entry in stripe transactions
						return $this->error($transactionDetails['message'],"",422);
					}
					//return $transactionDetails['data']['id'];
					//log Stripe info
					$chargeLogged = $this->logStripeChargeResponse($transactionDetails["data"],$this->bookingInfo);
					if($chargeLogged['status'] == false)
					{
						//logs in history
					}
					$transactionId = !empty($chargeLogged['data']['charge_id'])?$transactionDetails['data']['charge_id']:0;
					$btransactionId = "";


				}
				$ifInsert = $this->paymentTransaction($transactionId,$btransactionId);

				$color = Helper::bookingColors(1);
				$UpdateBooking = Booking::where('id',$this->input['booking_id'])->update(['booking_status' => 1,'color'=>$color['border'].','.$color['background']]);

				$message = "Booking has been started";

			}
		}else if ($this->input['status'] == 'no-show'){
			if($this->bookingInfo['booking_status'] == 0){
				$color = Helper::bookingColors(4);
				$UpdateBooking = Booking::where('id',$this->input['booking_id'])->update(['booking_status' => 4,'color'=>$color['border'].','.$color['background']]);
				if ($UpdateBooking) {
					$noShow_policy = Policy::where('policy_type','no-show')->where('business_id',$this->bookingInfo['business_id'])->first();
					if (!empty($noShow_policy)) {
						if ($noShow_policy->policy_condition == 2) {
							//NUMBER of HOURS BETWEEN TWO DATES
							$booking_date = new Carbon($this->bookingInfo['booking_date'].' '.$this->bookingInfo['booking_start_time']);
							$current_date = new Carbon();
							if ($current_date->greaterThan($booking_date)) {

								if($this->bookingInfo['booking_source'] == 'online'){
									//Deduct Payment
									$this->bookingInfo['booking_price'] = 0.5 ; //calculate from cancelation Policy
									



									//return $transactionDetails['data']['id'];
									// $transactionId = $transactionDetails['data']['id'];
									// $ifInsert = $this->paymentTransaction($transactionId);
								}



							}
						}
					}
				}
			}
		}else if ($this->input['status'] == 'cancel'){
			if($this->bookingInfo['booking_status'] == 0){
				$color = Helper::bookingColors(2);
				$UpdateBooking = Booking::where('id',$this->input['booking_id'])->update(['booking_status' => 2,'color'=>$color['border'].','.$color['background']]);
				if ($UpdateBooking) {
					$cancelation_policy = Policy::where('policy_type','cancel')->where('business_id',$this->bookingInfo['business_id'])->first();
					if (!empty($cancelation_policy)) {
						if ($cancelation_policy->policy_condition == 2) {
							//NUMBER of HOURS BETWEEN TWO DATES
							$created_date = new Carbon($this->bookingInfo['created_at']);
							$booking_date = new Carbon($this->bookingInfo['booking_date'].' '.$this->bookingInfo['booking_start_time']);
							if ($created_date->diffInHours($booking_date,false) >= ($cancelation_policy->duration * 24)) {
								$current_date = new Carbon();
								if ($created_date->diffInHours($current_date,false) >= ($cancelation_policy->duration * 24)) {
									// calculate and Deduct amount through stripe
									// Add transaction
								}
							}
						}
					}
				}
				$message = "Booking has been Cancelled";
			}
		}else if ($this->input['status'] == 'complete'){
			if($this->bookingInfo['booking_status'] == 1)
			{
				if($this->bookingInfo['booking_source'] == 'online')
				{
					// $transactionInfo = Transaction::where('booking_id',$this->bookingInfo['id'])->first();
					// if(!empty($transactionInfo))
					// {
					// 	$balancetransactioninfo = $this->stripe->StripeAccountBalanceTransaction();
					// 	if($balancetransactioninfo['status'])
					// 	{
					// 		$this->logBookingPendingBalacne($balancetransactioninfo["data"]);
					// 	}


					// }
				}

				$color = Helper::bookingColors(1);
				$UpdateBooking = Booking::where('id',$this->input['booking_id'])->update(['booking_status' => 3,'color'=>$color['border'].','.$color['background']]);

				$message = "Hello, this is a Twilio message!";
        		$recipients = ['+923453893330']; // Example phone numbers

        		//SendTwilioMessage::dispatch($message, $recipients);

				$message = "Booking has been Completed";

			}
		}

		if($UpdateBooking){

			//Send Notification

			//Send Email

			//Send Invoice if booking is completed

			// $userInfo = Helper::getUserById($this->bookingInfo['user_id']);
			// $provider = Helper::getUserById($this->bookingInfo['provider_id']);
			// $business = Helper::getBusinessById($this->bookingInfo['business_id']);

			// $userInfo->notify(new NewBooking($business,$userInfo,$provider,$bookingInfo,'user'));
			// $provider->notify(new NewBooking($business,$userInfo,$provider,$bookingInfo,'provider'));
			// $owner->notify(new NewBooking($business,$userInfo,$provider,$bookingInfo,'owner'));

		}
		return $this->success([],"Update Successfully");

	}
	public function getCustomerStripeAccount($id = ""){
		if(empty($id)){
			$id = Auth::id();
		}
		return Stripe_account::where('id',$id)->where('status',1)->first();
	}
	public function deductStripPayment($amount = 0, $payment_card_id = 0)
	{
		$data = ["data"=>[],"message"=> "","status"=>false];
		if(!empty($this->bookingInfo['booking_price']))
		{
			$amount = $this->bookingInfo['booking_price'];
		}
		if(empty($amount))
		{
			$data['message'] = "Amount must be greater than 0";
			return $data;
		}
		if(!empty($this->bookingInfo['payment_card_id']))
		{
			$payment_card_id = $this->bookingInfo['payment_card_id'];
		}

		if(empty($payment_card_id))
		{
			$data['message'] = "Payment source does'nt found";
			return $data;
		}
		$customerInfo = $this->getCustomerStripeAccount($payment_card_id);
		if(empty($customerInfo)){
			$data['message'] = "Card information doesn't found e.g Ref id: ".$payment_card_id; //"Customer information doesn't found";
			return $data;
		}
		$stripe = new \Stripe\StripeClient(env('STRIPE_TEST_SECRET_KEY'));

		try {
			$transactionsArr = array(
				"amount" =>$amount*100,
				"currency" => "usd",
				"description" =>  "You have booked a service"
			);
			$transactionsArr['customer'] = $customerInfo->customer_id;
			$transactionsArr['source'] =  $customerInfo->source_id;
			$response = $stripe->charges->create($transactionsArr);
			$data['data'] = $response;
			$data['message'] = "Payment has been charged Successfully";
			$data['status'] = true;

		} catch(Stripe_CardError $e) {
			$data['message'] = $e->getmessage();
		} catch (Stripe_InvalidRequestError $e) {
			// Invalid parameters were supplied to Stripe's API
			$data['message'] = $e->getmessage()."! Invalid parameters were supplied to Stripe's API";
		} catch (Stripe_AuthenticationError $e) {
			// Authentication with Stripe's API failed
			$data['message'] = $e->getmessage()."! Authentication with Stripe's API failed";
		} catch (Stripe_ApiConnectionError $e) {
			// Network communication with Stripe failed
			$data['message'] = $e->getmessage()."! Network communication with Stripe failed";
		} catch (Exception $e) {
			$data['message'] = "Something Went Wrong with Card! ".$e->getmessage();
		}catch (Stripe_Error $e) {
			// Display a very generic error to the user, and maybe send
			// yourself an email
			$data['message'] = "Something Went wrong! Please try agian";
		}

		return $data;

	}

	public function paymentTransaction($transaction_id = 0 , $bTransactionId = 0)
	{
		$stripe_charges =  Helper::calculateTaxAmount($this->bookingInfo['booking_price'],"stripe");

		//reference to booking
    	$str_result = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    	$reference =  substr(str_shuffle($str_result), 0, 8);
		$transactionsArr = array(
			'booking_id' => $this->bookingInfo['id'],
			'reference_id' => $reference.'-'.$this->bookingInfo['id'],
			'transaction_type' => 'Booking',
			'user_id' => $this->bookingInfo['user_id'],
			'user_type' => 'client',
			'business_id' => $this->bookingInfo['business_id'],
			'ondaq_fee' => $this->bookingInfo['ondaq_fee'],
			'transaction_fee' => $stripe_charges,
			'total_price' => $this->bookingInfo['booking_price'],
			'transaction_id' => $transaction_id,
			'balance_transaction_id' => $bTransactionId,
			'payment_method' => 'stripe',
			'date' => date('Y-m-d H:i:s'),
			'status' => '1'
		);

		$ifInsert = Transaction::insert($transactionsArr);
		if($ifInsert){
			return true;
		}
		return false;

	}

	public function transferStripPayment($bookingPrice=0)
	{
		$data = ["data"=>[],"message"=> "","status"=>false];
		if(empty($bookingPrice))
		{
			$data["message"] = "Amount must be greater than 0";
			return $data;
		}
		$user_id = Auth::id();
		$business = Helper::getBusinessByUserId($user_id);
		$stripeConnectAccount = Stripe_connect_account::where('business_id',$business->id)->first();
		if(empty($stripeConnectAccount) || empty($stripeConnectAccount->stripe_user_id))
		{
			$data["message"] = "To recieve payment please connect your account";
			return $data;
		}

		$stripeAmount = (($bookingPrice * 2.9 ) + 0.3 );
		$amount = $bookingPrice - $stripeAmount;

		$stripe = new \Stripe\StripeClient(env('STRIPE_TEST_SECRET_KEY'));
		try {
			$transferInfo = $stripe->transfers->create([
				'amount' => $amount*100,
				'currency' => 'usd',
				'destination' => $stripeConnectAccount->stripe_user_id,
				'transfer_group' => 'Ondaq-Transfer',
			]);
			$data['data'] = $transferInfo;
			$data['message'] = "Payment has been charged Successfully";
			$data['status'] = true;

		} catch(Stripe_CardError $e) {
			$data['message'] = $e->getmessage();
		} catch (Stripe_InvalidRequestError $e) {
			// Invalid parameters were supplied to Stripe's API
			$data['message'] = $e->getmessage()."! Invalid parameters were supplied to Stripe's API";
		} catch (Stripe_AuthenticationError $e) {
			// Authentication with Stripe's API failed
			$data['message'] = $e->getmessage()."! Authentication with Stripe's API failed";
		} catch (Stripe_ApiConnectionError $e) {
			// Network communication with Stripe failed
			$data['message'] = $e->getmessage()."! Network communication with Stripe failed";
		} catch (Exception $e) {
			$data['message'] = "Something Went Wrong with Card! ".$e->getmessage();
		}catch (Stripe_Error $e) {
			// Display a very generic error to the user, and maybe send
			// yourself an email
			$data['message'] = "Something Went wrong! Please try agian";
		}

		return $data;


	}

	 /**
	 * get Transactions.
	 *
	 * @param  \Illuminate\Http\Request  $request
	 * @return \Illuminate\Http\Response
	*/
	public function dt_transactions(Request $request)
	{
		$input = $request->all();

        $order_columns = ['id','reference_id',null,'transaction_type',null,'total_price','date'];

        $transactions_query = $this->transactions_query($input);

        // Order By
        if (isset($input['order'][0]['column']) && isset($order_columns[$input['order'][0]['column']])) {
            $transactions_query->orderBy($order_columns[$input['order'][0]['column']],$input['order'][0]['dir']);
        }else{
            $transactions_query->orderBy('id', 'DESC');
        }

        // Offset, Limit
        if ($input['start'] >= 0  && $input['length'] >= 0) {
            $transactions_query->offset($input['start']);
            $transactions_query->limit($input['length']);
        }

        $transactions = $transactions_query->get();

		$counters = $query_2 = DB::table("transactions")
        ->select(
            DB::raw('SUM(total_price) as gross_sale'),
            DB::raw('SUM((total_price - (transaction_fee - ondaq_fee))) as net_sale'),
            DB::raw('SUM(ondaq_fee) as paye')
        )->first();

        $counters = [
        	'paye' => $counters->paye,
        	'net_sale' => $counters->net_sale,
        	'gross_sale' => $counters->gross_sale
        ];



        $data = [];
        if(!empty($transactions) && count($transactions) > 0){
			//$counters['paye'] =  array_sum(array_column($transactions, 'ondaq_fee'));
			//$counters['net_sale'] =  (array_sum(array_column($transactions, 'total_price')) - ( $counters['paye'] + array_sum(array_column($transactions, 'transaction_fee')) ) );
			//$counters['gross_sale'] =  array_sum(array_column($transactions, 'total_price'));

            foreach ($transactions as $key => $transaction) {
                $output = [];
                $output[] = "#".$transaction->id;
                $output[] = $transaction->reference_id;
                if (!empty($input['platform'])) {
                    if ($input['platform'] == 'mob') {
                        $output[] = $transaction->user->picture;
                        $output[] = $transaction->user->name;
                    }
                }else{
                    $img = '<img class="img-fluid" src="https://s3.us-east-2.amazonaws.com/images.ondaq.com/profile/profile.svg" alt="">';
                    $output[] = '<a class="manname" href="#/(0)">'.$img.' '.$transaction->user->name.' </a>';
                }
                $output[] = $transaction->transaction_type;

                $desc= '';
                if ($transaction->transaction_type == 'No-Show') {
                	$desc = "The client didn't showed.";
                }elseif ($transaction->transaction_type == 'Canceleation') {
                	$desc = 'The client cancel the booking.';
                }elseif ($transaction->transaction_type == 'Booking' || $transaction->transaction_type == 'Queue') {
                	$desc = 'You were schedule booking with '.$transaction->booking->user_firstname.' '.$transaction->booking->user_lastname;
                }
                $output[] = $desc;
                $output[] = '$'.$transaction->total_price;
                $output[] = date('m/d/Y H:i a',strtotime($transaction->date));

                $data[] = $output;
            }
        }

		$outsfsput = [
			'recordsTotal'=> $this->all_transactions_count($input),
			'recordsFiltered'=> $this->filtered_transactions_count($input),
			"data"=>$data,
			"stats" => $counters
		];
		if (empty($input['platform'])) {
			$outsfsput["draw"]= $input['draw'];
		}
        return response()->json($outsfsput, 200);


		// 	$query = Transaction::withe('Booking')->where('status',1);

		// 	//if type is not empty get Queue or booking
		// 	if(!empty($input['type'])){
		//       	$query->where('transactions.transaction_type', '=', $input['type']);
		//       }

		//       $transactions = $query->get();
		//       $result['paye'] =  array_sum(array_column($transactions, 'ondaq_fee'));
		//       $result['net_sale'] =  (array_sum(array_column($transactions, 'total_price')) - ( $result['paye'] + array_sum(array_column($transactions, 'transaction_fee')) ) );
		//       $result['gross_sale'] =  array_sum(array_column($transactions, 'total_price'));
		// $result['transacrions'] =  $transactions;
		//       return $this->success($transactions);

	}

    public function transactions_query($input){
	 	$query = Transaction::with('booking')->with('user:id,name,picture')->where('status',1);
	 	if (!empty($input['business_id'])) {
	 		$query->where('business_id', '=', $input['business_id']);
	 	}
	 	// if (!empty($input['employee'])) {
	 	// 	$query->where('user_id', '=', $input['employee']);
	 	// }

		if(!empty($input['type'])){
			$query->where('transaction_type',$input['type']);
		}

	 	//Duration
	 	if (!empty($input['start_date']) && !empty($input['end_date'])) {
	 		if ($input['start_date'] == $input['end_date']) {
	 			$query->whereDate('transactions.date', '=', $input['start_date'] );
	 		}else{
		 		$query->whereBetween('transactions.date', [$input['start_date'],$input['end_date']]);
	 		}
	 	}elseif (!empty($input['start_date'])) {
	 		$query->whereDate('transactions.date', '>', $input['start_date'] );
	 	}elseif (!empty($input['end_date'])) {
	 		$query->whereDate('transactions.date', '<', $input['end_date'] );
	 	}


        // $today = date('Y-m-d');
        // if($input['duration'] == 'today'){
        //     $query->whereDate('transactions.date', '=', $today);
        // }else if($input['duration'] == 'past'){
        //     $query->whereDate('transactions.date', '<', $today);
        // }else if($input['duration'] == 'upcoming'){
        //     $query->whereDate('transactions.date', '>', $today);
        // }else if($input['duration'] == 'specific'){
        //     $query->whereDate('transactions.date', '=', $input['date'] );
        // }else if($input['duration'] == 'date_range'){
        //     $query->whereBetween('transactions.date', [$input['from'],$input['to']]);
        // }

        return $query;
    }
    public function all_transactions_count($input){
        return $q = Transaction::where("business_id",$input["business_id"])->count();
    }
    public function filtered_transactions_count($input){
        $query = $this->transactions_query($input);
        return $query->count();
    }


    public function getEmployeesWithQueues($business_id)
    {
        $userInfo = Auth::user();
        if(empty($userInfo)){
            return $this->error('Login to continue');
        }

        $businessInfo = Helper::getBusinessById($business_id);
        if(empty($businessInfo)){
            return $this->error('Business not found');
        }

        $businessEmployees = Employee::select("employees.id","employees.user_id")->with('user:id,name,picture')->where('business_id',$business_id)->where("product",1)->get();
        if(empty($businessEmployees)){
            return $this->error('No Employee found');
        }
        $allQueues = [];
        foreach ($businessEmployees as $key => $businessEmployee) {
            $todayActiveQueues = $this->employeeQueues($businessEmployee->user_id,$businessInfo->id);
            $businessEmployee['activeQueues'] = $todayActiveQueues;
            $allQueues[] = $businessEmployee;
            ///$allQueues[$businessEmployee['user']['first_name'].' '.$businessEmployee['user']['last_name']] = ['activeQueues'=>$todayActiveQueues];

            //$allQueues['professionalInfo'] = $businessEmployee;
        }

        return $this->success($allQueues);

    }

	public function getClientQueues()
    {
        $user_id = Auth::id();
        if(empty($user_id)){
            return $this->error('Login to continue');
        }
		$queues = Booking::select("provider_id","business_id")
		->with('provider:id,name,picture')
		->where('user_id',$user_id)
		->where('booking_type',1)
		->whereDate('booking_date',date('Y-m-d'))->get();

        $allQueues = [];
        if(!empty($queues)){
			foreach ($queues as $key => $queue) {
				$todayActiveQueues = $this->employeeQueues($queue->provider_id,$queue->business_id);
				$queue['activeQueues'] = $todayActiveQueues;
				$allQueues[] = $queue;
			}
		}
        return $this->success($allQueues);
    }
    public function employeeQueues($providerId=0,$businessId='')
    {
        $queues = [];
        $q = Booking::with('BoookingServices')->where('provider_id',$providerId)->where('booking_type',1)->where('booking_date',date('Y-m-d'))->where(function($query){$query->where('booking_status',0)->orWhere('booking_status',1);});
		if (!empty($businessId)) {
			$q->where('business_id',$businessId);
		}

		$bookingInfo = $q->get();
        if(empty($bookingInfo)){
            return $queues;
        }
        $to_time = strtotime(date('Y-m-d H:i:s'));
        $serviceProgress = [];
        $total_duration = 0;
        foreach ($bookingInfo as $key => $value) {
            $booking_start_time = strtotime($value->booking_start_time);
            foreach ($value->BoookingServices as $key_2 => $service) {
                $start_time = $booking_start_time;
                if($key_2 != 0){
                    $start_time = ($booking_start_time+$total_duration);
                }
                $total_duration += $service->duration;

                $time_difference_service = round(abs($to_time - $start_time) / 60,2);
                $percentage = ($time_difference_service/$service->duration)*100;
                if ($percentage > 100) {
                    $percentage = 100;
                }
                $serviceProgress[] = round($percentage,0);
                $value->BoookingServices[$key_2]['progress_bar'] = round($percentage,0);

            }
            $queues[] = $value;
            $time_difference = round(abs($to_time - $booking_start_time) / 60,2);
            $percentageOverall = ($time_difference/$value->booking_duration)*100;
            $queues[$key]['overAllProgress'] = $value->booking_status == 1 ? round($percentageOverall,0) : 0;
            $queues[$key]['serviceProgress'] = $serviceProgress;
        }
        return $queues;
    }




	//Request a Quotes
	public function addRequestQuote(Request $request){
		if (Auth::check()) {
			$validator = Validator::make($request->all(), [
				'request_date' => 'required',
				'description' => 'required',
				'employee' => 'required',
				'services' => 'required',
				'render_location' => 'required',
				'price' => 'required',
				'slug'=>'required',
			]);
			if ($validator->fails()) {
				$j_errors = $validator->errors();
				$errors = (array) json_decode($j_errors);
				$key = array_key_first($errors);
				return $this->error($errors[$key][0],"",422);
			}

			$user_id = Auth::id();
			$input = $request->all();
			$business = Helper::getBusinessBySlug($input['slug']);

			if(!empty($business))
			{
				// Add new Request a quote
				$request_a_quote = Req_a_quote::create([
					'user_id' => $user_id,
					'tracking_id' => IdGenerator::generate(['table' => 'req_a_quotes','field'=>'tracking_id','length'=>9,'prefix'=>'RQ-']),
					'employee_id' => $input['employee']['id'],
					'employee_user_id' => $input['employee']['user_id'],
					'business_id' => $business->id,
					"render_location" => $input['render_location'],
					'price' => $input['price'],
					'description' => $input['description'],
					'booking_date' => date('Y-m-d',strtotime($input['request_date'])),
					'status' => 1,
					'updated_at' => date('Y-m-d H:i:s'),
					'created_at' => date('Y-m-d H:i:s')
				]);
				if(empty($request_a_quote->id)){
					return $this->error("Something went wrong while adding a request a quote");
				}

				// Add new Request ofer
				$request_a_quote_offer = Raq_offer::create([
					'user_id' => $user_id,
					'employee_id' => $input['employee']['id'],
					'employee_user_id' => $input['employee']['user_id'],
					'business_id' => $business->id,
					'offer_from' => 'Client',
					'req_a_quote_id' => $request_a_quote->id,
					'price' => $input['price'],
					'date' => $input['request_date'],
					"render_location" => $input['render_location'],
					'status' => 1,
					'created_at' => date('Y-m-d H:i:s'),
					'updated_at' => date('Y-m-d H:i:s'),
				]);
				if(!$request_a_quote_offer){
					return $this->error("Something went wrong while adding a request a quote offer");
				}

				// Add Request Services
				for ($i=0; $i < count($input['services']); $i++) {
					if ($input['services'][$i]['selected'] == true) {
						$raq_service = Raq_service::create([
							'req_a_quote_id' => $request_a_quote->id,
							'service_id' => $input['services'][$i]['service_id'],
							"business_service_id" => $input['services'][$i]['business_service_id'],
							'status' => 1
						]);
					}
				}
				if(!$raq_service){
					return $this->error("Something went wrong while adding request services");
				}

				// Add Message Conversation

				return $this->success();

			}else{
				return $this->error("No business found.");
			}
		}else{
			return $this->notLogin();
		}
	}

	public function dt_RequestQuote(Request $request){
		$input = $request->all();
        $order_columns = ['id','booking_date',null,null,'render_location','price',null,'status','created_at'];
        $raq_query = $this->raq_query($input);

        // Order By
        if (isset($input['order'][0]['column']) && isset($order_columns[$input['order'][0]['column']])) {
            $raq_query->orderBy($order_columns[$input['order'][0]['column']],$input['order'][0]['dir']);
        }else{
            $raq_query->orderBy('id', 'DESC');
        }

        // Offset, Limit
        if ($input['start'] >= 0  && $input['length'] >= 0) {
            $raq_query->offset($input['start']);
            $raq_query->limit($input['length']);
        }

        $quotations = $raq_query->get();
        $data = [];
        if(!empty($quotations) && count($quotations) > 0){
            foreach ($quotations as $key => $quotation) {
                $output = [];
                $output[] = "#".$quotation->id;
                $output[] = date('m/d/Y',strtotime($quotation->booking_date));
                if (!empty($input['platform'])) {
                    if ($input['platform'] == 'mob') {
                        $output[] = $quotation->user->picture;
                        $output[] = $quotation->user->name;
                    }
                }else{
                    $img = '<img class="img-fluid" src="https://s3.us-east-2.amazonaws.com/images.ondaq.com/profile/profile.svg" alt="">';
                    $output[] = '<a class="manname" href="#/(0)">'.$img.' '.$quotation->user->name.' </a>';
                }
                if (!empty($input['platform'])) {
                    if ($input['platform'] == 'mob') {
                        $output[] = $quotation->provider->picture;
                        $output[] = $quotation->provider->name;
                    }
                }else{
                    $img = '<img class="img-fluid" src="https://s3.us-east-2.amazonaws.com/images.ondaq.com/profile/profile.svg" alt="">';
                    $output[] = '<a class="manname" href="#/(0)">'.$img.' '.$quotation->provider->name.' </a>';
                }
                $output[] = $quotation->render_location;
                $output[] = '$'.$quotation->price;
                $output[] = $quotation->description;


				// Status
                if (!empty($input['platform'])) {
                    if ($input['platform'] == 'mob') {
                        $output[] = $quotation->status;
                    }
                }else{
                    if ($quotation->status == 1) {
                    	$output[] = '<a class="solds" href="#">Active</a>';
                    }else if($quotation->status == 1){
                        $output[] = '<a class="Adds" href="#">Rejected</a>';
                    }else if($quotation->status == 3){
                        $output[] = '<a class="losts" href="#">Canceled</a>';
                    }
                }

                $output[] = date('m/d/Y H:i a',strtotime($quotation->created_at));

                $data[] = $output;
            }
        }

		$outsfsput = [
			'recordsTotal'=> $this->all_raq_count($input),
			'recordsFiltered'=> $this->filtered_raq_count($input),
			"data"=>$data
		];
		if (empty($input['platform'])) {
			$outsfsput["draw"]= $input['draw'];
		}
        return response()->json($outsfsput, 200);
	}
    public function raq_query($input){
	 	$query = Req_a_quote::with('provider:id,name,picture')->with('user:id,name,picture');
	 	if (!empty($input['business_id'])) {
	 		$query->where('business_id', '=', $input['business_id']);
	 	}
	 	if (!empty($input['employee'])) {
	 		$query->where('employee_user_id', '=', $input['employee']);
	 	}

		if(!empty($input['status'])){
			$query->where('status',$input['status']);
		}

	 	//Duration
	 	if (!empty($input['start_date']) && !empty($input['end_date'])) {
	 		if ($input['start_date'] == $input['end_date']) {
	 			$query->whereDate('booking_date', '=', $input['start_date'] );
	 		}else{
		 		$query->whereBetween('booking_date', [$input['start_date'],$input['end_date']]);
	 		}
	 	}elseif (!empty($input['start_date'])) {
	 		$query->whereDate('booking_date', '>', $input['start_date']);
	 	}elseif (!empty($input['end_date'])) {
	 		$query->whereDate('booking_date', '<', $input['end_date']);
	 	}

        return $query;
    }
    public function all_raq_count($input){
        return $q = Req_a_quote::where("business_id",$input['business_id'])->count();
    }
    public function filtered_raq_count($input){
        $query = $this->raq_query($input);
        return $query->count();
    }

 	/**
     * Create Data tables for bookings.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
    */

	public function dtAppointments(Request $request , $req_from,$business_id='',$type=null){
        $input = $request->all();
		$user_id = Auth::id();
		if ($req_from == 'business') {
			if (empty($business_id)) {
				return $this->error("Business is missing.");
			}
		}

        $order_columns = ['id','created_at','user_firstname','provider_firstname',null,null,'booking_date','booking_source'];
        $bookingType = 2;
        if(!empty($input['booking_type'])){
        	$bookingType = $input['booking_type'];
        }
        $booking_query = $this->booking_query($req_from,$business_id,$user_id,$input,$bookingType);

        // Order By
        if (isset($input['order'][0]['column']) && isset($order_columns[$input['order'][0]['column']])) {
            $booking_query->orderBy($order_columns[$input['order'][0]['column']],$input['order'][0]['dir']);
        }else{
            $booking_query->orderBy('id', 'DESC');
        }

        // Offset, Limit
        if ($input['start'] >= 0  && $input['length'] >= 0) {
            $booking_query->offset($input['start']);
            $booking_query->limit($input['length']);
        }

        //If called from dashboard
        if(!empty($type)){
        	$booking_query->limit(5);
        }

        $bookings = $booking_query->get();
        $data = [];
        if(!empty($bookings) && count($bookings) > 0){
            foreach ($bookings as $key => $booking) {
                $output = [];
                $output[] = "#".$booking->id;

                if (!empty($input['platform'])) {
                    if ($input['platform'] == 'mob') {
                        $output[] = $booking->user_profile_picture;
                        $output[] = $booking->user_firstname.' '.$booking->user_lastname;

						$output[] = $booking->provider_profile_picture;
                        $output[] = $booking->provider_firstname.' '.$booking->provider_lastname;
                    }
                }else{
                    $img = '<img class="img-fluid" src="https://s3.us-east-2.amazonaws.com/images.ondaq.com/profile/profile.svg" alt="">';
                    $output[] = '<a class="manname" href="#/(0)">'.$img.' '.$booking->user_firstname.' '.$booking->user_lastname.' </a>';
					$output[] = '<a class="manname" href="#/(0)">'.$img.' '.$booking->provider_firstname.' '.$booking->provider_lastname.' </a>';
                }

                $output[] = '$'.$booking->booking_price;
				$output[] = $booking->booking_duration.' min';

				// Status
                if (!empty($input['platform'])) {
                    if ($input['platform'] == 'mob') {
                        $output[] = $booking->booking_status;
                    }
                }else{
                    if ($booking->booking_status == 0) {
                        $output[] = '<a class="Adds" href="#">Pending</a>';
                    }else if($booking->booking_status == 1){
                        $output[] = '<a class="losts" href="#">Started</a>';
                    }else if($booking->booking_status == 2){
                        $output[] = '<a class="losts" href="#">Canceled</a>';
                    }else if($booking->booking_status == 3){
                        $output[] = '<a class="solds" href="#">Completed</a>';
                    }else if($booking->booking_status == 4){
                        $output[] = '<a class="Adds" href="#">No Show</a>';
                    }
                }
                //$output[] = $booking->booking_source;
                $output[] = date('m/d/y',strtotime($booking->booking_date)).' '.date('g:i a',strtotime($booking->booking_start_time)).' - '.date('g:i a',strtotime($booking->booking_end_time));

                //$output[] = date('H:i a',strtotime($booking->booking_end_time));

                // Services
                // $services = '';
                // if (count($booking->BoookingServices) > 0) {
                //     foreach ($booking->BoookingServices as $key => $serv) {
                //         if ($key > 0) {
                //             $services .= ', ';
                //         }
                //         $services .= $serv->title;
                //     }
                // }
                // $output[] = $services;




				// Actions
                if (empty($input['platform']) && $type != "dashboard") {
                    if ($booking->booking_status == 0) {
                        // $output[] = '
						// 	<div class="dropdown">
						// 		<button type="button" class="filter-btns" class="dropdown-toggle" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">Action</button>
						// 		<div class="dropdown-menu timeline btn-content">
						// 			<a class="solds" data-id="'.$booking->id.'" data-status="start" href="#">Start</a>
						// 			<a class="losts" data-id="'.$booking->id.'" data-status="cancel" href="#">Cancel</a>
						// 			<a data-id="'.$booking->id.'" data-status="no-show" class="opens" href="#">No Show</a>
						// 		</div>
						// 	</div>';
						$btns = '';
						if ($req_from == 'business') {
							if($input['duration'] == 'past'){
								$btns .= '<a class="losts mr-1 change-status" data-id="'.$booking->id.'" data-status="cancel" href="#/">Cancel</a>';
								$btns .= '<a data-id="'.$booking->id.'" data-status="no-show" class="Adds change-status" href="#/">No Show</a>';
							}elseif ($input['duration'] == 'upcoming') {
								$btns .= '<a class="losts mr-1 change-status" data-id="'.$booking->id.'" data-status="cancel" href="#/">Cancel</a>';
								// $btns .= '<a class="losts mr-1" data-id="'.$booking->id.'" href="../messages/Messages.vue"> <i class="fa fa-envelope"></i> </a>';
							}else{
								$btns .= '<a class="solds mr-1 change-status" data-id="'.$booking->id.'" data-status="start" href="#/">Start</a>';
								$btns .= '<a class="losts mr-1 change-status" data-id="'.$booking->id.'" data-status="cancel" href="#/">Cancel</a>';
								$btns .= '<a data-id="'.$booking->id.'" data-status="no-show" class="Adds change-status" href="#/">No Show</a>';
							}
							// $btns .= '<a class="solds mr-1 change-status" data-id="'.$booking->id.'" data-status="start" href="#/">Start</a>';
						}
						// $btns .= '<a class="losts mr-1 change-status" data-id="'.$booking->id.'" data-status="cancel" href="#/">Cancel</a>';
						// if ($req_from == 'business') {
						// 	$btns .= '<a data-id="'.$booking->id.'" data-status="no-show" class="Adds change-status" href="#/">No Show</a>';
						// }
						$output[] = $btns;
                    }else if($booking->booking_status == 1){
                        $output[] = '<a class="solds change-status" data-id="'.$booking->id.'" data-status="complete" href="#/">Finish</a>';
                    }else if($booking->booking_status == 2){
                        $output[] = '<a class="Adds" href="#">Reschedule</a>';
                    }else if($booking->booking_status == 3 ){
						if($req_from != 'business' && $booking->review_given == 0){
							$output[] = '<a class="Adds give-review" data-business_img="'.$booking->business_image.'" data-business_name="'.$booking->business_name.'" data-emp_name="'.$booking->provider_firstname.' '.$booking->provider_lastname.'" data-id="'.$booking->id.'" data-bs-toggle="modal" data-bs-target="#exampleModal" href="javascript:;" id="test" >Give Review</a>';
						}else{
							$output[] = "";
						}
                    }else if($booking->booking_status == 4){
                        // $output[] = '<a class="Adds" href="#">Reschedule</a>';
                    }
                }



				// if (empty($input['platform'])) {
				// 	if($booking->status == 0){
                //         $output[] = '<a class="losts" href="#">Started</a>';
                //     }else if($booking->status == 2){
                //         $output[] = '<a class="losts" href="#">Canceled</a>';
                //     }else if($booking->status == 3){
                //         $output[] = '<a class="solds" href="#">Completed</a>';
                //     }else if($booking->status == 4){
                //         $output[] = '<a class="opens" href="#">No Show</a>';
                //     }
				// }

                $data[] = $output;
            }
        }

		$outsfsput = [
			'recordsTotal'=> $this->all_booking_count($req_from,$business_id,$user_id,$input,$bookingType),
			'recordsFiltered'=> $this->filtered_bookings_count($req_from,$business_id,$user_id,$input,$bookingType),
			"data"=>$data
		];
		if (empty($input['platform'])) {
			$outsfsput["draw"]= $input['draw'];
		}
        return response()->json($outsfsput, 200);
    }
    public function booking_query($req_from,$business_id='',$user_id,$input,$type = 2){
        $q = Booking::with("BoookingServices")->where("booking_type",$type);
        if ($req_from == 'business') {
			$q->where("business_id",$business_id);
		}else{
			$q->where("user_id",$user_id);
		}

		if (!empty($input['search']['value'])) {
            $q->where('user_firstname','LIKE','%'.$input["search"]["value"].'%');
        }
        if (!empty($input['user_id'])) {
            $q->where('user_id',$input['user_id']);
        }
        //Duration
	 	if (!empty($input['duration'])) {
            $today = date('Y-m-d');
            if($input['duration'] == 'today'){
                $q->whereDate('booking_date', '=', $today);
            }else if($input['duration'] == 'past'){
                $q->whereDate('booking_date', '<', $today);
            }else if($input['duration'] == 'upcoming'){
				if ($req_from == 'client') {
					$q->whereDate('booking_date', '>=', $today);
				}else{
					$q->whereDate('booking_date', '>', $today);
				}
            }else if($input['duration'] == 'specific'){
                $q->whereDate('booking_date', '=', $input['date'] );
            }else if($input['duration'] == 'date_range'){
                $q->whereBetween('booking_date', [$input['from'],$input['to']]);
            }
        }
        return $q;
    }
    public function all_booking_count($req_from,$business_id,$user_id,$input,$type = 2){
        $q = Booking::where("booking_type",$type);
		if ($req_from == 'business') {
			$q->where("business_id",$business_id);
		}else{
			$q->where("user_id",$user_id);
		}
		return $q->count();
    }
    public function filtered_bookings_count($req_from,$business_id,$user_id,$input,$bookingType){
        $query = $this->booking_query($req_from,$business_id,$user_id,$input,$bookingType);
        return $query->count();
    }



	// Requested Services Booking
	public function reuestedServicesBooking(Request $request){
		if (Auth::check()) {
			$validator = Validator::make($request->all(), [
				'request_date' => 'required',
				'description' => 'required',
				'employee' => 'required',
				'services' => 'required',
				'render_location' => 'required',
				'price' => 'required',
				'slug'=>'required',
			]);
			if ($validator->fails()) {
				$j_errors = $validator->errors();
				$errors = (array) json_decode($j_errors);
				$key = array_key_first($errors);
				return $this->error($errors[$key][0],"",422);
			}

			$user_id = Auth::id();
			$input = $request->all();
			$business = Helper::getBusinessBySlug($input['slug']);

			if(!empty($business))
			{
				// Add new Request a quote
				$request_a_quote = Req_a_quote::create([
					'user_id' => $user_id,
					'employee_id' => $input['employee']['id'],
					'employee_user_id' => $input['employee']['user_id'],
					'business_id' => $business->id,
					"render_location" => $input['render_location'],
					'price' => $input['price'],
					'description' => $input['description'],
					'booking_date' => date('Y-m-d',strtotime($input['request_date'])),
					'status' => 1,
					'updated_at' => date('Y-m-d H:i:s'),
					'created_at' => date('Y-m-d H:i:s')
				]);
				if(empty($request_a_quote->id)){
					return $this->error("Something went wrong while adding a request a quote");
				}

				// Add new Request offer
				$request_a_quote_offer = Raq_offer::create([
					'user_id' => $user_id,
					'employee_id' => $input['employee']['id'],
					'employee_user_id' => $input['employee']['user_id'],
					'business_id' => $business->id,
					'offer_from' => 'Client',
					'req_a_quote_id' => $request_a_quote->id,
					'price' => $input['price'],
					'date' => $input['request_date'],
					"render_location" => $input['render_location'],
					'status' => 1,
					'created_at' => date('Y-m-d H:i:s'),
					'updated_at' => date('Y-m-d H:i:s'),
				]);
				if(!$request_a_quote_offer){
					return $this->error("Something went wrong while adding a request a quote offer");
				}

				// Add Request Services
				for ($i=0; $i < count($input['services']); $i++) {
					if ($input['services'][$i]['selected'] == true) {
						$raq_service = Raq_service::create([
							'req_a_quote_id' => $request_a_quote->id,
							'service_id' => $input['services'][$i]['service_id'],
							"business_service_id" => $input['services'][$i]['business_service_id'],
							'status' => 1
						]);
					}
				}
				if(!$raq_service){
					return $this->error("Something went wrong while adding request services");
				}

				// Add Message Conversation

				return $this->success();

			}else{
				return $this->error("No business found.");
			}
		}else{
			return $this->notLogin();
		}
	}

	public function logBookingPendingBalacne($transferInfo = [])
	{
		if(empty($pendingBalacne))
		{
			return false;
		}

		$transfer_details = [
			'business_id' => $this->bookingInfo['business_id'],
			'booking_id' =>  $this->bookingInfo['id'],
			'balance_transaction_id'=>!empty($transferInfo->id)?$transferInfo->id:"",
			'transfer_amount' => !empty($transferInfo->amount)?$transferInfo->amount:"",
			'available_on' => !empty($transferInfo->available_on)?date("Y-m-d H:i",$transferInfo->available_on):"",
			"stripe_fee" => !empty($transferInfo->fee)?$transferInfo->fee:0,
			"final_amount" => !empty($transferInfo->net)?$transferInfo->net:0,
			"status" => !empty($transferInfo->status)?$transferInfo->status:0,
			'updated_at' => date('Y-m-d H:i:s'),
			'created_at' => date('Y-m-d H:i:s')
		];

		// $transfer_details = [
		// 	'business_id' => $this->bookingInfo['business_id'],
		// 	'booking_id' =>  $this->bookingInfo['id'],
		// 	'transfer_id'=>!empty($transferInfo->id)?$transferInfo->id:"",
		// 	'transfer_amount' => !empty($transferInfo->amount)?$transferInfo->amount:"",
		// 	'balance_transaction_id' => !empty($transferInfo->balance_transaction)?$transferInfo->balance_transaction:0,
		// 	"destination_account" => !empty($transferInfo->destination)?$transferInfo->destination:"",
		// 	'destination_payment' =>!empty($transferInfo->destination_payment)?$transferInfo->destination_payment:"",
		// 	'source_type' =>!empty($transferInfo->source_type)?$transferInfo->source_type:"",
		// 	'created' => !empty($transferInfo->created)?date("Y-m-d H:i",$transferInfo->created):"",
		// 	'object' => !empty($transferInfo->object)?$transferInfo->object:"",
		// 	'amount_reversed' => !empty($transferInfo->amount_reversed)?$transferInfo->amount_reversed:"",
		// 	'updated_at' => date('Y-m-d H:i:s'),
		// 	'created_at' => date('Y-m-d H:i:s')
		// ];
		Stripe_transfer::create($transfer_details);

		return true;


	}

	public function bookingPayment()
	{
		$business = Helper::getBusinessById($this->bookingInfo['business_id']);

		$tax = 0;
		$amount = $this->bookingInfo['booking_price'];
		if(!empty($business->free_trial))
		{
			$tax =  Helper::calculateTaxAmount($amount,"stripe_Payout");
		}
		elseif(!empty($business->payment_method))
		{
			$tax =  Helper::calculateTaxAmount($amount,"stripe_Payout");
		}
		else
		{
			$tax =  Helper::calculateTaxAmount($amount,"all");
		}


		$source = $this->bookingInfo['payment_card_id'];
		$businessId = $this->bookingInfo['business_id'];


		$transactionDetails  = $this->stripe->chargeCustomer($amount,$source,$tax,$businessId);

		return $transactionDetails;

	}

	public function logStripeChargeResponse($response = [] , $bookingInfo = [])
	{
		$data = ["data"=>[],"message"=>"","status"=>false];
		if(empty($response) || empty($bookingInfo) )
    	{
    		$data["message"] = "Something is missing!";
    		return $data;
    	}

		$paymentArr =[
			'business_id'=>!empty($bookingInfo['business_id'])?$bookingInfo['business_id']:0,
			'booking_id'=>!empty($bookingInfo['id'])?$bookingInfo['id']:0,
			'booking_ref'=>!empty($bookingInfo['tracking_id'])?$bookingInfo['tracking_id']:0,
			'total_amount'=>!empty($response['amount_received'])?$response['amount_received']/100:0,
			'total_fee'=>0,
			'fee_details'=> "",
			'net_amount'=>0,
			'created'=>!empty($response['created'])?date("Y-m-d H:i:s", strtotime($response['created'])):0,
			'available_on'=>"",
			'charge_id'=> "",
			'application_fee_amount'=>!empty($response['application_fee_amount'])?$response['application_fee_amount']:0,
			'intent_id'=>!empty($response['id'])?$response['id']:"",
			'payment_status'=>!empty($response['status'])?$response['status']:0,
			"amount_status"=>"",
		];

		if(!empty($response['charges']) && !empty($response['charges']['data']) &&  !empty($response['charges']['data'][0]) && !empty($response['charges']['data'][0]['balance_transaction']) )
		{
			$bid = $response['charges']['data'][0]['balance_transaction'];
			$result = $this->stripe->StripeAccountBalanceTransaction($bid);
			if($result['status'] == true)
			{
				$balanceResponse = $result['data'];

				$paymentArr['total_amount'] = !empty($balanceResponse['amount'])?$balanceResponse['amount']/100:0;
				$paymentArr['total_fee'] = !empty($balanceResponse['fee'])?$balanceResponse['fee']/100:0;
				$paymentArr['fee_details'] = !empty($balanceResponse['fee_details'])?json_encode($balanceResponse['fee_details']):"";
				$paymentArr['net_amount'] = !empty($balanceResponse['net'])?$balanceResponse['net']/100:0;
				$paymentArr['created'] = !empty($balanceResponse['created'])?date("Y-m-d H:i:s", $balanceResponse['created']):0;
				$paymentArr['available_on'] = !empty($balanceResponse['available_on'])?date("Y-m-d", $balanceResponse['available_on']):0;
				$paymentArr['charge_id'] = !empty($balanceResponse['source'])?$balanceResponse['source']:0;
				$paymentArr["amount_status"] = !empty($balanceResponse['status'])?$balanceResponse['status']:0;

			}
		}

		$logged = Stripe_payment::create($paymentArr);

		if(!$logged)
    	{
    		$data["message"] = "Something Went wrong while logging";
    		return $data;
    	}

		$data["message"] = "Response logged";
		$data["status"] = true;
		$data["data"] = $paymentArr;

		return $data;
	}

	function testSMS() {

		$test = Helper::sendMessage("You are number 2 in the queue. Please be ready in the next 30 minutes. ",["+923453893330","+13476629707"],true);
		return $this->success($test);
	}
	public function testEmail(){
		$data = array('name'=>"Virat Gandhi");
   
		Mail::send(['text'=>'mail'], $data, function($message) {
			$message->to('simondoul18@gmail.com', 'Tutorials Point')->subject
				('Laravel Basic Testing Mail');
			$message->from('noreply@ondaq.com','Virat Gandhi');
		});
		echo "Basic Email Sent. Check your inbox.";
	}

}