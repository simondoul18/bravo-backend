<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Traits\ApiResponser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use App\Models\User;
use App\Models\Employee;
use App\Models\Business;
use App\Models\Business_category;
use App\Models\Business_type;
// use App\Models\Business_service;
use App\Models\Business_service_employee;
use App\Models\Business_hour;
use App\Models\Employee_hour;
use App\Models\Category;
use App\Models\Review;
// use App\Models\Review_helpful;
use App\Models\Booking;
use App\Models\Gallery;
use App\Models\Deal;
use App\Models\Stripe_account;
use App\Models\Policy;
use App\Models\Favourite;
use App\Helpers\Helper;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Carbon\CarbonPeriod;
use App\Traits\Aws;
use Stevebauman\Location\Facades\Location;
use App\Models\User_setting;
use App\Models\Booking_service;
use App\Models\Review_helpful;
use Illuminate\Support\Facades\Log;
// use App\Services\StripeApis;
use DateTime;
use DateTimeZone;

class BusinessController extends Controller
{
	use ApiResponser,Aws;
	// public function __construct(StripeApis $stripe)
    // {
    //     $this->stripe = $stripe;
    // }
	// Business
	public function businessDetail(Request $request,$slug){
		$input = $request->all();
		$action = empty($input['type']) ? '':$input['type'];
		$day = Helper::get_day(date('Y-m-d'));
		// Get Business Detail
		$business = [];
		if (!empty($input['id'])) {
			$business_id = $input['id'];
		}else{
			$business_info = Business::select("businesses.*",DB::raw("
				(SELECT COUNT(*) FROM reviews WHERE reviews.business_id = businesses.id AND reviews.is_deleted = 0) as total_reviews,
				(SELECT ROUND(AVG(overall_rating),1) FROM reviews WHERE business_id = businesses.id AND is_deleted = 0) as rating,
				(SELECT COUNT(*) FROM galleries WHERE business_id = businesses.id AND status = 1) as totalPhotos,
				(SELECT COUNT(*) from business_hours where business_hours.business_id = businesses.id and day = ".$day." and isOpen = 1 and time(start_time) < '".date('H:i:s')."' and time(end_time) > '".date('H:i:s')."') as is_open"
			))
			->with('businessHours')->with('covidPoints')->with("user_settings")->with("categories:id,title")->where('status',1)->where('title_slug',$slug)->first();
			if (empty($business_info)) {
				return $this->notFound();
			}
			if (!empty($input['user_id'])) {
				$user_id = $input['user_id'];
				$favourite = Favourite::select("status")->where('user_id', $user_id)->where('business_id', $business_info['id'])->first();
				$business_info->is_favorite = empty($favourite) ? 0 : $favourite->status;
			}

			$business_info->businessIsOpen = $business_info->is_open > 0 ? 1 : 0;
			
			$business_info->queue_status = 0; 
			$business_info->queue_walkin = 0; 
			if(!empty($business_info->user_settings) && $business_info->user_settings->queue_status == 1)
			{
				$business_info->queue_status = 1; //if queue is enabled
			}
			if(!empty($business_info->user_settings) && $business_info->user_settings->queue_walkin == 1)
			{
				$business_info->queue_walkin = 1; //if queues is enable but only walkin
			}

			$business_info->out_of_range = 0;
			
			//get radius of client from business locatio
			if (!empty($business_info) && !empty($business_info->queue_status) && !empty($business_info->businessIsOpen)) {

				//get the current user ip
				$currentUserLat = "";
				$currentUserLng = "";

				$ip = $request->ip(); //Dynamic IP address

				if(!empty($ip))
				{
					//get the current location using ip
					$currentUserInfo = Location::get($ip);
					if(!empty($currentUserInfo))
					{
						$currentUserLat = !empty($currentUserInfo->latitude)?$currentUserInfo->latitude:"";
						$currentUserLng = !empty($currentUserInfo->longitude)?$currentUserInfo->longitude:"";
					}
				}

				

					$businessLat = !empty($business_info->lat)?$business_info->lat:"";
					$businessLng = !empty($business_info->lng)?$business_info->lng:"";
					$miles = Helper::getDistance($currentUserLat,$currentUserLng,$businessLat,$businessLng);
					if($miles > 10)
					{
						$business_info->out_of_range = 1;
					}
				
			}
			$business['business_info'] = $business_info;
			$business_id = $business_info->id;
		}
		//return $this->success($business_info);


		if ($action == 'deals') {
			// Deals
			$business['deals'] = Deal::with("user")->where('business_id',$business_id)->whereDate('endDate','>=', Carbon::now())->where('status',1)->get();
		}elseif ($action == 'services') {
			// Services
			$business['services'] = Category::whereHas('business_category', function (Builder $query) use ($business_id) {
                $query->where('business_id', '=', $business_id);
            })->with(['business_service' => function ($query){
                $query->select(["business_services.*","services.title"])
                ->leftJoin('services', 'services.id', '=', 'service_id');
            }])->get();
		}elseif ($action == 'queue') {
			// Queues
			$businessEmployees = Employee::with('user')->where('business_id',$business_id)->whereIn("product",[1,3])->get();
			//est waiting time
			if(!empty($businessEmployees)){
				
				foreach($businessEmployees as $key => $employee){
					$employeeId = $employee->user_id;
					$empEstWaitingTiome = $this->est_waiting_time($employeeId);
					$businessEmployees[$key]['estTime'] =  $this->convertToHoursAndMinutes($empEstWaitingTiome);
				}
			}
			$business['employees'] = $businessEmployees;
		}
		if ($action == 'gallery' || empty($action) || $action == 'overview') {
			$business['photos'] = Gallery::where('business_id',$business_id)->where('status',1)->get();
		}
		//,ROUND(AVG(recommendation),1) as avg_recommendation
		if ($action == 'reviews' || empty($action) || $action == 'overview') {
			$reviews_stats = Review::select(DB::raw("
				(SELECT COUNT(*) FROM reviews WHERE business_id = $business_id AND is_deleted = 0 AND status = 1 AND overall_rating = 5) as total_five,
				(SELECT COUNT(*) FROM reviews WHERE business_id = $business_id AND is_deleted = 0 AND status = 1 AND (overall_rating = 4 OR (overall_rating >= 4 AND overall_rating < 5))) as total_four,
				(SELECT COUNT(*) FROM reviews WHERE business_id = $business_id AND is_deleted = 0 AND status = 1 AND (overall_rating = 3 OR (overall_rating >= 3 AND overall_rating < 4))) as total_three,
				(SELECT COUNT(*) FROM reviews WHERE business_id = $business_id AND is_deleted = 0 AND status = 1 AND (overall_rating = 2 OR (overall_rating >= 2 AND overall_rating < 3))) as total_two,
				(SELECT COUNT(*) FROM reviews WHERE business_id = $business_id AND is_deleted = 0 AND status = 1 AND overall_rating = 1) as total_one,
				COUNT(*) as total_reviews,ROUND(AVG(overall_rating),1) as rating,ROUND(AVG(overall),1) as avg_quality,ROUND(AVG(services),1) as avg_skill,ROUND(AVG(value),1) as avg_communication,ROUND(AVG(punctuality),1) as avg_timing"))
			->where('business_id',$business_id)->where('is_deleted',0)->where('status',1)->first();
			$business['reviewsStats'] = $reviews_stats;
			$businessReviews = Review::with('user')
			->where('business_id', $business_id)
			->where('is_deleted', 0)
			->where('status', 1)
			->get();

			$user_id = 0;
			if (!empty($input['user_id'])) {
				$user_id = $input['user_id'];
			}

			if(!empty($businessReviews)) {

				foreach($businessReviews as $key => $review) {
					$services = Booking_service::where("booking_id", $review->booking_id)->pluck('title')->toArray();
					$concatenatedServices = implode(', ', $services);
					$businessReviews[$key]["services"] =  $concatenatedServices;

					$reviewHelpful = Review_helpful::where("review_id", $review->id)->count();
					$businessReviews[$key]["helpful"] =  $reviewHelpful;
					
					
					$isHelpful = Review_helpful::where("user_id",$user_id)->where("review_id",$review->id)->count();
					$businessReviews[$key]["isHelpful"] =  ($isHelpful > 0)?true:false;
				}
			}

			$business['reviews'] = $businessReviews;

		
		}

		return $this->success($business);
	}

	public function businessProfile($id)  
	{
		if (Auth::check()) {
			//$user_id = Auth::id();
            $business_info = Helper::getBusinessById($id);
			//$timeZones = DateTimeZone::listIdentifiers(DateTimeZone::AMERICA);
			$timeZones = [
				"America/New_York" => "Eastern",
				"America/Chicago" => "Central",
				"America/Denver" => "Mountain",
				"America/Phoenix" => "Mountain",
				"America/Los_Angeles" => "Pacific",
				"America/Anchorage" => "Alaska",
				"Pacific/Honolulu" => "Hawaii-Aleutian",
			];

			foreach ($timeZones as $timeZone => &$displayName) {
				$dateTimeZone = new DateTimeZone($timeZone);
				$now = new DateTime("now", $dateTimeZone);
				
				if ($now->format('I') == '1') {
					// If currently observing DST, update the display name
					$displayName .= " Daylight Time";
				} else {
					// If not observing DST, update the display name
					$displayName .= " Standard Time";
				}

				 // Get the current GMT offset in hours and minutes
				 $gmtOffsetHours = $dateTimeZone->getOffset($now) / 3600;
				 $gmtOffsetMinutes = abs(($dateTimeZone->getOffset($now) % 3600) / 60);
				 
				 // Append GMT offset information
				 $displayName .= " (GMT";
				 $displayName .= $gmtOffsetHours >= 0 ? '+' : '-';
				 $displayName .= abs($gmtOffsetHours);
				 $displayName .= ($gmtOffsetMinutes > 0) ? ':' . str_pad($gmtOffsetMinutes, 2, '0', STR_PAD_LEFT) : '';
				 $displayName .= ")";
			}

			if (empty($business_info)) {
				return $this->notFound();
			}
			$data["business_info"] = $business_info;
			$data["time_zones"] = $timeZones;
			return $this->success($data);
		}else{
            return $this->error("Access Denied. Please login first.");
        }
	}

    public function updateBusinessProfile(Request $request)
    {
        if (Auth::check()) {
            $validator = Validator::make($request->all(), [
				'title' => 'required|string',
				'phone' => 'required|max:20',
				'address.complete_address' => 'required',
				'address.lat' => 'required',
				'address.lng' => 'required',
				'address.country' => 'required',
				'address.state' => 'required',
				'address.city' => 'required',
				'address.street' => 'required',
				'address.zip' => 'required',
				'description' => 'required',
				'business_id' => 'required',
                //'profile_pic' => 'required',
                'dialCode' => 'required',
				'timeZone' => 'required',
            ]);
            if ($validator->fails()) {
                $j_errors = $validator->errors();
                $errors = (array) json_decode($j_errors);
                $key = array_key_first($errors);
                return $this->error($errors[$key][0],"",422);
            }

            $input = $request->all();

			//$user_id = Auth::id();
            //$business_info = Helper::getBusinessByUserId($user_id);
			// if(empty($input['business_id'])){
			// 	return $this->error("Business not found");
			// }


			$facilities = '';
		   	if (!empty($input['facilities']['free_parking'])) {
				$facilities .= 'Free Parking: '.$input['facilities']['free_parking'].',';
		   	}
			if (!empty($input['facilities']['wifi'])) {
				$facilities .= 'Free Wifi: '.$input['facilities']['wifi'].',';
		   	}
			if (!empty($input['facilities']['reception'])) {
				$facilities .= 'Reception: '.$input['facilities']['reception'].',';
		   	}
			if (!empty($input['facilities']['kids_friendly'])) {
				$facilities .= 'Kids Friendly: '.$input['facilities']['kids_friendly'].',';
		   	}

		   	if (!empty($input['facilities']['language'])) {
		   		$languages = implode ("_", $input['facilities']['language']);
				$facilities .= 'Languages: '.$languages.',';
		   	}
		   	if (!empty($input['facilities']['Payment_method'])) {
		   		$payment = implode ("_", $input['facilities']['Payment_method']);
				$facilities .= 'Payment methods: '.$payment;
		   	}

			$data = [
                "title" => $input['title'],
				'title_slug' =>  Str::slug($input['title']),
                'phone' => '+'.$input['dialCode'].preg_replace('/\D+/', '', $input['phone']),
				'address' => $input['address']['complete_address'],
				'country' => $input['address']['country'],
				'city' => $input['address']['city'],
				'state' => $input['address']['state'],
				'zip' => $input['address']['zip'],
				'street' => $input['address']['street'],
				'lat' => $input['address']['lat'],
				'lng' => $input['address']['lng'],
				'apt_suite' => $input['apt_suite'],
				'description' => $input['description'],
				'special_instructions' => $input['special_instructions'],
				'cancellation_policy' => $input['cancellation_policy'],
				'timeZone' => $input['timeZone'],
                //'profile_pic' => $profile_pic,
                'facilities' => $facilities,
                'status' => $input['status'],
				'updated_at' => date('Y-m-d H:i:s')
            ];

			if (!empty($input['profile_pic'])) {
				if (preg_match('/^data:image\/(\w+);base64,/', $input['profile_pic'])) {
					$data['profile_pic'] = $this->AWS_FileUpload('base64', $input['profile_pic'],'business_logo');
				}
			}

            // Add new Deal
            $resp = Business::where('id',$input['business_id'])->update($data);
            if(!$resp){
                return $this->error("Something went wrong while updating business profile");
            }else{
    			//$businessInfo = Helper::getBusinessById($id);
                return $this->success("","Successfully updated.");
            }
        }else{
            return $this->notLogin();
        }
    }


    public function assignedBusinesses(){
        if (Auth::check()) {
            $user_id = Auth::id();
            $business_list = Helper::getUserAssignedBusiness($user_id);
			return $this->success($business_list);
        }else{
            return $this->error("Access Denied. Please login first.");
        }
	}

	// public function businessDetail(Request $request,$slug){
	// 	$input = $request->all();
	// 	if (empty($input['type'])) {
	// 		$action = '';
	// 	}else{
	// 		$action = $input['type'];
	// 	}
	// 	//if (empty($action) || $action == 'overview') {
	// 	if (empty($action)) {
	// 		$business = Business::with('businessHours')->with('covidPoints')->with('photos')->with('reviews.user:id,name,picture')->where('title_slug',$slug)->first();
	// 		$id = $business->id;
	// 		$reviews_stats = Review::select(DB::raw("
	// 			(SELECT COUNT(*) FROM reviews WHERE business_id = ".$business->id." AND is_deleted = 0 AND overall_rating = 5) as total_five,
	// 			(SELECT COUNT(*) FROM reviews WHERE business_id = ".$business->id." AND is_deleted = 0 AND overall_rating = 4) as total_four,
	// 			(SELECT COUNT(*) FROM reviews WHERE business_id = ".$business->id." AND is_deleted = 0 AND overall_rating = 3) as total_three,
	// 			(SELECT COUNT(*) FROM reviews WHERE business_id = ".$business->id." AND is_deleted = 0 AND overall_rating = 2) as total_two,
	// 			(SELECT COUNT(*) FROM reviews WHERE business_id = ".$business->id." AND is_deleted = 0 AND overall_rating = 1) as total_one,
	// 			COUNT(*) as total_reviews,ROUND(AVG(overall_rating),1) as rating,ROUND(AVG(quality),1) as avg_quality,ROUND(AVG(skill),1) as avg_skill,ROUND(AVG(communication),1) as avg_communication,ROUND(AVG(timing),1) as avg_timing,ROUND(AVG(recommendation),1) as avg_recommendation"))
	// 		->where('business_id',$business->id)->where('is_deleted',0)->first();
	// 		$business['reviewsStats'] = $reviews_stats;

	// 		$business['services'] = Category::whereHas('business_category', function (Builder $query) use ($id) {
    //             $query->where('business_id', '=', $id);
    //         })->with(['business_service' => function ($query){
    //             $query->select(["business_services.*","services.title"])
    //             ->leftJoin('services', 'services.id', '=', 'service_id');
    //         }])->get();
	// 		//$business['services'] = [];
	// 		$business['deals'] = [];
	// 		$business['queue'] = [];
	// 	}elseif ($action == 'forHeaderDetail') {
	// 		$business = Business::where('title_slug',$slug)->first();
	// 		$id = $business->id;
	// 		$reviews_stats = Review::select(DB::raw("COUNT(*) as total_reviews,ROUND(AVG(overall_rating),1) as rating"))
	// 		->where('business_id',$business->id)->where('is_deleted',0)->first();
	// 		$business['reviewsStats'] = $reviews_stats;
	// 	}
	// 	// }elseif ($action == 'services') {
	// 	// 	$business = Business::with('businessHours')->with('covidPoints')->where('title_slug',$slug)->first();
	// 	// 	$id = $business->id;
	// 	// 	$reviews_stats = Review::select(DB::raw("
	// 	// 		(SELECT COUNT(*) FROM reviews WHERE business_id = ".$business->id." AND is_deleted = 0 AND overall_rating = 5) as total_five,
	// 	// 		(SELECT COUNT(*) FROM reviews WHERE business_id = ".$business->id." AND is_deleted = 0 AND overall_rating = 4) as total_four,
	// 	// 		(SELECT COUNT(*) FROM reviews WHERE business_id = ".$business->id." AND is_deleted = 0 AND overall_rating = 3) as total_three,
	// 	// 		(SELECT COUNT(*) FROM reviews WHERE business_id = ".$business->id." AND is_deleted = 0 AND overall_rating = 2) as total_two,
	// 	// 		(SELECT COUNT(*) FROM reviews WHERE business_id = ".$business->id." AND is_deleted = 0 AND overall_rating = 1) as total_one,
	// 	// 		COUNT(*) as total_reviews,ROUND(AVG(overall_rating),1) as rating,ROUND(AVG(quality),1) as avg_quality,ROUND(AVG(skill),1) as avg_skill,ROUND(AVG(communication),1) as avg_communication,ROUND(AVG(timing),1) as avg_timing,ROUND(AVG(recommendation),1) as avg_recommendation"))
	// 	// 	->where('business_id',$business->id)->where('is_deleted',0)->first();
	// 	// 	$business['reviewsStats'] = $reviews_stats;
	// 	// 	$business['services'] = Category::whereHas('business_category', function (Builder $query) use ($id) {
    //     //         $query->where('business_id', '=', $id);
    //     //     })->with(['business_service' => function ($query){
    //     //         $query->select(["business_services.*","services.title"])
    //     //         ->leftJoin('services', 'services.id', '=', 'service_id');
    //     //     }])->get();
	// 	// 	$business['queue'] = [];
	// 	// 	$business['deals'] = [];
	// 	// 	$business['reviews'] = [];
	// 	// 	$business['photos'] = [];
	// 	// }
	// 	//$location = explode("-",$input['location']);
	// 	//$query = Business::where('state',$location[1])->where('city','like','%'.$location[0].'%');

	// 	return $this->success($business);
	// }

	public function homeBusinesses(Request $request){
		$q = Business::select("businesses.title","title_slug","profile_pic","city","state",DB::raw("
			(SELECT COUNT(*) FROM reviews WHERE reviews.business_id = businesses.id AND reviews.is_deleted = 0) as total_reviews,
			(SELECT ROUND(AVG(overall_rating),1) FROM reviews WHERE business_id = businesses.id AND is_deleted = 0) as rating"))
			->where('status',1);
		$q->limit(8)->orderBy('rating','desc')->orderBy('total_reviews','desc');

		$query = $q;
		if ($request->has('location') && !empty($request->query('location'))) {
			$location = explode("-",$request->query('location'));
			$query->where('city','like','%'.$location[0].'%');
		}
		$business = $query->get();

		if (empty($business) || count($business) == 0) {
			$business = $q->get();
		}

		// Get Distance
		if (!empty($business)) {

			//get the current user ip
			$currentUserLat = "";
			$currentUserLng = "";

			$ip = $request->ip(); //Dynamic IP address

			if(!empty($ip))
			{
				//get the current location using ip
				$currentUserInfo = Location::get($ip);
				if(!empty($currentUserInfo))
				{
					$currentUserLat = !empty($currentUserInfo->latitude)?$currentUserInfo->latitude:"";
					$currentUserLng = !empty($currentUserInfo->longitude)?$currentUserInfo->longitude:"";
				}
			}

			foreach($business as $key => $busines)
			{

				$businessLat = !empty($busines->lat)?$busines->lat:"";
				$businessLng = !empty($busines->lng)?$busines->lng:"";
				$business[$key]['distance'] = Helper::getDistance($currentUserLat,$currentUserLng,$businessLat,$businessLng);
			}
		}
		return $this->success($business);
	}



	public function businessListing(Request $request){
		$validator = Validator::make($request->all(), [
			'location' => 'required|string'
		]);
		if ($validator->fails()) {
			$j_errors = $validator->errors();
			$errors = (array) json_decode($j_errors);
			$key = array_key_first($errors);
			return $this->error($errors[$key][0],"",422);
		}
		$input = $request->all();

		//get the current user ip
		$currentUserLat = "";
		$currentUserLng = "";

		$ip = $request->ip(); //Dynamic IP address

		if(!empty($ip))
		{
			//get the current location using ip
			$currentUserInfo = Location::get($ip);
			if(!empty($currentUserInfo))
			{
				$currentUserLat = !empty($currentUserInfo->latitude)?$currentUserInfo->latitude:"";
				$currentUserLng = !empty($currentUserInfo->longitude)?$currentUserInfo->longitude:"";
			}
		}

		$location = explode("-",$input['location']);
		$day = Helper::get_day(date('Y-m-d'));
		
		$query = Business::select("businesses.*",
			DB::raw("(SELECT CHAR_LENGTH(ROUND(MIN(cost),0)) FROM business_services WHERE business_id = businesses.id AND status = 1) as min_price_length"),
			DB::raw("(SELECT CHAR_LENGTH(ROUND(MAX(cost),0)) FROM business_services WHERE business_id = businesses.id AND status = 1) as max_price_length"),
			DB::raw("(SELECT ROUND(AVG(overall_rating),1) FROM reviews WHERE business_id = businesses.id AND is_deleted = 0) as rating"),
			DB::raw("(SELECT COUNT(*) FROM reviews WHERE business_id = businesses.id AND is_deleted = 0) as total_reviews"),
			DB::raw("(select COUNT(*) from business_hours where business_hours.business_id = businesses.id and day = ".$day." and isOpen = 1 and time(start_time) < '".date('H:i:s')."' and time(end_time) > '".date('H:i:s')."') as is_open")
		)->with("user_settings")->with("businessEmployees")->with("categories:id,title")->where('status',1);
		// $query->with('gallery');

		// Location
		$query->where('city','like','%'.$location[0].'%');
		if (!empty($location[1])) {
			$query->where('state',$location[1]);
		}

		// Service
		if (!empty($input['service'])) {
			$srevice_str = str_replace('+',' ',$input['service']);
			$service = DB::table("services")->where('title','like',$srevice_str)->first();
			if (!empty($service)) {
				$query->whereHas('businessServices', function (Builder $q) use ($service) {
					$q->where('service_id',$service->id);
				},'>', 0);
			}
		}

		// Render Location
		$render_str = trim($input['render']);
		if (empty($input['render'])) {
			$render_str = 'business';
		}
		$query->where('service_render_location','like','%'.$render_str.'%');

		// Price Limit
		if (!empty($input['currency_range'])) {
			if (strlen($input['currency_range']) === 1) {
				$price_limit = [0,10];
			}elseif (strlen($input['currency_range']) === 2) {
				$price_limit = [10,100];
			}elseif (strlen($input['currency_range']) === 3) {
				$price_limit = [10,100];
			}elseif (strlen($input['currency_range']) === 4) {
				$price_limit = [999,100000000000];
			}
			$query->where(function ($q) use ($price_limit){
				$q->selectRaw('COUNT(*)')->from('business_services')
				->where('business_services.business_id','=',DB::raw("businesses.id"))
				->where('status',1)
				->whereBetween('cost',$price_limit);
			},">", 0);
			// $query->where(function ($q) use ($price_limit){
			// 	$q->selectRaw('CHAR_LENGTH(ROUND(MAX(cost),0))')->from('business_services')
			// 	->where('business_services.business_id','=',DB::raw("businesses.id"));
			// },'>=', $price_limit);
		}

		// Open Now
		if (filter_var($input['isOpen'], FILTER_VALIDATE_BOOLEAN)  == true) {
			$query->whereHas('businessHours', function (Builder $q) {
				$day = Helper::get_day(date('Y-m-d'));
				$q->where('business_hours.business_id','=',DB::raw("businesses.id"))
				->where('day',$day)
				->where('isOpen',1)
				->whereTime('start_time', '<', date('H:i:s'))
				->whereTime('end_time', '>', date('H:i:s'));
			},'>', 0);
		}

		// Has today Deals
		if (filter_var($input['hasDeals'], FILTER_VALIDATE_BOOLEAN)  == true) {
			$query->whereHas('deals', function (Builder $q) {
				$q->where('deals.business_id','=',DB::raw("businesses.id"))
				->where('status',1)
				->whereDate('startDate','<=', Carbon::now())
				->whereDate('endDate','>=', Carbon::now());
			},'>', 0);
		}

		// Rating
		if (!empty($input['rating'])) {
			$query->where(function ($q) {
				$q->selectRaw('ROUND(AVG(overall_rating),1)')->from('reviews')
				->where('reviews.business_id','=',DB::raw("businesses.id"))
				->where('is_deleted',0);
			},">=", $input['rating']);
		}

		// Gender
		if (!empty($input['gender'])) {
			$gender = $input['gender'];
			$query->where(function ($q) use ($gender) {
				$q->selectRaw('COUNT(*)')->from('business_service_employees')
				->leftJoin('users', 'users.id', '=', 'business_service_employees.user_id')
				->where('business_service_employees.business_id','=',DB::raw("businesses.id"))
				->where('users.gender',$gender)
				->where('users.user_status',1);
			},">", 0);
		}

		// Distance
		if (!empty($currentUserLat) && !empty($currentUserLng) && !empty($input['distance'])) {
			$query->whereRaw('(ST_Distance_Sphere(point(businesses.lng, businesses.lat),point('.$currentUserLng.', '.$currentUserLat.'))*.000621371192) <= '.$input['distance']);
			// $query->having('distance',"<=",$input['distance']);
		}

		// Order By
		$orderBy = $input['sort_by'];
		if (empty($input['sort_by'])) {
			$orderBy = "featured";
		}
		if ($orderBy === 'featured') {
			//$query->orderBy('is_featured', 'DESC');
		}elseif ($orderBy === 'new') {
			$query->orderBy('id', 'DESC');
		}elseif ($orderBy === 'top_rated') {
			$query->orderBy('rating', 'DESC');
		}

		$data = $query->simplePaginate($input['limit']);
		
		if(!empty($data)){
			foreach($data  as $key => $business){
				if(!empty($business->businessEmployees)) {
					$i = 0;
					$empEstWaitingTiome = [];
					foreach($business->businessEmployees as $employee){
						$employeeId = $employee->user_id;
						if($employee->product != 2)
						{
							$empEstWaitingTiome[] = $this->est_waiting_time($employeeId);
							$i++;
						}
					}
					if(empty($i))
					{	
						$data[$key]->est_waiting_time = 0;
					}
					else
					{
						$estimated_waiting_time = min($empEstWaitingTiome);
						$data[$key]->est_waiting_time = $this->convertToHoursAndMinutes($estimated_waiting_time);
					}
				}else{
					$data[$key]->est_waiting_time = 0;
				}
				
			}
		}
		return $this->success($data);
	}

	public function listYourBusiness(Request $request){
		$validator = Validator::make($request->all(), [
			'business_name' => 'required|string',
			'phone' => 'required|max:20',
			'address.complete_address' => 'required',
			'address.lat' => 'required',
			'address.lng' => 'required',
			'address.country' => 'required',
			'address.state' => 'required',
			'address.city' => 'required',
			'address.street' => 'required',
			'address.zip' => 'required',
			'reference' => 'required',
			// 'business_strength' => 'required',
			//'service_render_position.location' => 'required'
		]);
		if ($validator->fails()) {
			$j_errors = $validator->errors();
			$errors = (array) json_decode($j_errors);
			$key = array_key_first($errors);
			return $this->error($errors[$key][0],"",422);
		}
		$input = $request->all();

		if ($input['service_render_position']['business'] == 'no' && $input['service_render_position']['client'] == 'no') {
			return $this->error("Please choose render location.","",422);
		}
		$user = '';
		if (Auth::check()) {
			$user_id = Auth::id();
		}else{
			//validate User
			$user = Helper::getUserByEmail($input['owner_email']);
			if (empty($user)) {
				$user = User::create([
					"name" => $input['owner_first_name']." ".$input['owner_last_name'],
					'first_name' => $input['owner_first_name'],
					'last_name' => $input['owner_last_name'],
					'password' => bcrypt($input['owner_password']),
					//'phone' => '+'.$input['dialCode'].preg_replace('/\D+/', '', $input['phone']),
					'gender' => ucfirst($input['gender']),
					'email' => $input['owner_email'],
					'updated_at' => date('Y-m-d H:i:s'),
					'created_at' => date('Y-m-d H:i:s')
				]);
				$user['is_owner'] = 1;
				if (!empty($user->id)) {
					Log::info('New User created for Business. User: '.$user->id);
					$user_id = $user->id;
				}else{
					return $this->error("Sorry Something wrong. User Does not created.");
				}
			}else{
				$user_id = $user->id;
			}
		}
		$rendered_location = 1;
		if ($input['service_render_position']['business'] == 'yes' && $input['service_render_position']['client'] == 'yes') 
		{
			$rendered_location = 3;
		}
		else if($input['service_render_position']['business'] == 'no' && $input['service_render_position']['client'] == 'yes')
		{
			$rendered_location = 2;
		}
		

		$businessArr = [
			'user_id' => $user_id,
			"title" => $input['business_name'],
			'title_slug' =>  Str::slug($input['business_name']),
			'phone' => '+'.$input['dialCode'].preg_replace('/\D+/', '', $input['phone']),
			'address' => $input['address']['complete_address'],
			'country' => $input['address']['country'],
			'city' => $input['address']['city'],
			'state' => $input['address']['state'],
			'zip' => $input['address']['zip'],
			'street' => $input['address']['street'],
			'lat' => $input['address']['lat'],
			'lng' => $input['address']['lng'],
			'reference' => $input['reference'],
			// 'payment_method' => $input['subscription']['type'],
			'service_render_location' => $rendered_location,
			'plan_days' => 30,
			'plan_start_date' => date('Y-m-d'),
			'plan_expiry_date' => date('Y-m-d',strtotime("+30 days")),
			'updated_at' => date('Y-m-d H:i:s'),
			'created_at' => date('Y-m-d H:i:s')
		];

		// if ($input['subscription']['type'] == 2) {
		// 	$businessArr['subscription_plan_id'] = $input['subscription']['plan']['id'];
		// }
		// echo "<pre>"; print_r($businessArr);exit;
		if(!empty($input['apt'])){
			$businessArr['apt_suite'] = $input['apt'];
		}

		// if(!empty($input['business_strength'])){
		// 	if ($input['business_strength'] == 'individual') {
		// 		$businessArr['strenght'] = 1;
		// 	}elseif ($input['business_strength'] == 'team') {
		// 		$businessArr['strenght'] = 2;
		// 	}
		// }
		if ($input['service_render_position']['business'] == 'yes' && $input['service_render_position']['client'] == 'yes') {
			$rp = 'business,client';
		}elseif ($input['service_render_position']['business'] == 'yes') {
			$rp = 'business';
		}elseif ($input['service_render_position']['client'] == 'yes') {
			$rp = 'client';
		}
		$businessArr['service_render_location'] = $rp;

		if ($input['service_render_position']['client'] == 'yes') {
			if (!empty($input['service_render_position']['distance'])) {
				$businessArr['serving_distance'] = $input['service_render_position']['distance'];
			}
		}

		$businessId = Business::create($businessArr)->id;
		$business_login_data = [
			'has_business'=>0,
			'id'=>'',
			'list' => [],
			'active_business'=>[]
		];
		if($businessId){
			Log::info('New Business created: '.$businessId);
			// Login Data
			$business_login_data['has_business'] = 1;
			$business_login_data['id'] = $businessId;
			$business_login_data['list'] = Helper::getUserAssignedBusiness($user_id);
			$business_login_data['active_business'] = [
				'id'=>$businessId,
				'slug'=>$businessArr['title_slug'],
				'title'=>$businessArr['title'],
				'picture'=>'',
				'is_owner'=>1,
				'profile_completetion'=>0
			];

			// Add business types
			if(!empty($input['businessTypes'])){
				Log::info('Business types starts.');
				foreach ($input['businessTypes'] as $key => $bType) {
					$business_type = new Business_type;
					$business_type->business_id = $businessId;
					$business_type->type_id = $bType['id'];
					$business_type->created_at = date('Y-m-d H:i:s');
					$business_type->save();
					Log::info('Business Type created.');
					if(!empty($business_type->id)){
						$categories = Category::where('type_id',$business_type->type_id)->get();
						Log::info('Add categories related to Business Types.');
						if(!empty($categories)){
							foreach ($categories as $key => $cate) {
								$busienssSelectedCate = [
									'business_id' => $businessId,
									'category_id' => $cate->id,
									'updated_at' => date('Y-m-d H:i:s'),
									'created_at' => date('Y-m-d H:i:s')
								];
								Business_category::create($busienssSelectedCate);
								Log::info('Business category added successfully.');
							}
						}
					}
				}
			}

			// Add Business Hours
			$days = array("", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday");
			for ($i=1; $i < 8; $i++) {
				$hoursArr = [
					'business_id'=>$businessId,
					'title'=>$days[$i],
					'day'=>$i,
					'start_time' => NULL,
					'end_time' => NULL
				];
				Business_hour::create($hoursArr);
				Log::info('Business Houes created.');
			}

			// Add cancelation policy
			$policyTypes=[
				['cancel','Booking Cancelation Policy','The customer can cancel their booking without any charge.'],
				['no-show','No Show Policy',"There will be no charge if customer don't arrived."]
			];
			for ($i=0; $i < 2; $i++) {
				Policy::create([

					'user_id' => $user_id,
					'business_id' => $businessId,
					'title' => $policyTypes[$i][1],
					'description' => $policyTypes[$i][2],
					'policy_type' => $policyTypes[$i][0],
					'policy_condition' => 1,
					'updated_at' => date('Y-m-d H:i:s'),
					'created_at' => date('Y-m-d H:i:s')
				]);
				Log::info('Business Policy created.');
			}

			// Add Employee
			$employee_id = Employee::create([
				'business_id' => $businessId,
				'user_id' => $user_id,
				'role' =>  1,
				'updated_at' => date('Y-m-d H:i:s'),
				'created_at' => date('Y-m-d H:i:s')
			])->id;
			if (!empty($employee_id)) {
				for ($i=1; $i < 8; $i++) {
					$empHoursArr = [
						'business_id'=>$businessId,
						'employee_id' => $employee_id,
						'title'=>$days[$i],
						'day'=>$i,
						'start_time' => NULL,
						'end_time' => NULL,
						'breakStart' => NULL,
						'breakEnd' => NULL,
						'minTime' => NULL,
						'maxTime' => NULL,
					];
					Employee_hour::create($empHoursArr);
				}
			}

			//entry for user settings to avoid any erros with laravel joins -> business owner only
			User_setting::insert([
				'queue_status' => 0,
				'queue_walkin' => 0,
				'user_id' => $user_id,
				'updated_at' => date('Y-m-d H:i:s'),
				'created_at' => date('Y-m-d H:i:s')
			]);
			Log::info('User settings created.');
		}


		if (empty($user)) {
			$token = '';
		}else{
			$token = $user->createToken('API Token')->plainTextToken;
		}

		return $this->success([
			'businessInfo' => $businessArr,
			'token' => $token,
			'user' => $user,
			'business' => $business_login_data,
		]);
    }

	// Employees
    public function businessEmployees($value=''){
    	if (Auth::check()) {
    		$user_id = Auth::id();
    		$businessInfo = Helper::getBusinessByUserId($user_id);
    		if(!empty($businessInfo)){
    			$businessEmployees = Employee::with('user')->with('employeeHours')->with('profession')->where('business_id',$businessInfo->id)->get();
				//Helper::getBusinessEmployees($businessInfo->id);
    			if(!empty($businessEmployees)){
    				return $this->success($businessEmployees);
    			}else{
    				return $this->error("No business.");
    			}
    		}else{
    			return $this->error("Business not found.");
    		}
    	}else{
			return $this->error("Not login.");
		}
   	}
	public function getBusinessId($business_id=''){
		if (empty($business_id)) {
			if (Auth::check()) {
				$user_id = Auth::id();
				$business = Helper::getBusinessByUserId($user_id);
				$business_id = $business->id;
			}
			// else{
			// 	return $this->success([]);
			// }
		}if (!is_numeric($business_id)) {
			$businessInfo  = Business::where('title_slug',$business_id)->first();
			$business_id = $businessInfo->id;
		}
		return $business_id;
	}
	public function employeesList(Request $request){
		$employees = [];
		if ($request->has('business_id') && !empty($request->business_id)) {
			$business_id = $request->business_id;
		}elseif($request->has('slug') && !empty($request->slug)){
			$businessInfo = Helper::getBusinessBySlug($request->slug);
			if (!empty($businessInfo)) {
				$business_id = $businessInfo->id;
			}
		}else{
			$businessInfo = Helper::getBusinessByUserId(Auth::id());
			if (!empty($businessInfo)) {
				$business_id = $businessInfo->id;
			}
		}

		if (!empty($business_id)) {
			$q = Employee::with('user:id,name,first_name')->where('business_id',$business_id);
			if (!empty($request->profession) && $request->profession == true) {
				$q->with('profession');
			}
			if (!empty($request->employeeHours) && $request->employeeHours == true) {
				$q->with('employeeHours');
			}
			$q->where('status',1);
			if (!empty($request->status) && $request->status === 'all') {
				$q->orWhere('status',0);
			}
			$employees = $q->get();
		}
		return $this->success($employees);
   	}
	public function employeesListForMS($business_id=''){
		if (empty($business_id)) {
			if (Auth::check()) {
				$user_id = Auth::id();
				$business = Helper::getBusinessByUserId($user_id);
				$business_id = $business->id;
			}else{
				return $this->success([]);
			}
		}if (!is_numeric($business_id)) {
			$businessInfo  = Business::where('title_slug',$business_id)->first();
			$business_id = $businessInfo->id;
		}
		$employees = Employee::with('user:id,name,picture')->where('business_id',$business_id)->where('status',1)->get();
		$options = [];
		if (!empty($employees) && count($employees) > 0) {
			foreach ($employees as $key => $employee) {
				$options[] = ['label'=>$employee->user->name,'value'=>$employee,'disabled'=>false];
			}
		}
		return $this->success($options);
   	}

   	public function addEmployee(Request $request){
   		if (Auth::check()) {
   			$validator = Validator::make($request->all(), [
	            'first_name' => 'required|string|max:255',
	            'last_name' => 'required|string|max:255',
	            'email' => 'required|string|email',
	            'phone' => 'required|max:20',
				'address.complete_address' => 'required',
	            'dialCode' => 'required',
	            'profession' => 'required',
	            'role' => 'required',
				'product' => 'required',
	            'gender' => 'required|string|max:10',
				'render_location'=> 'required',
	            // 'services'=> 'required',
	            // 'licence'=> 'required',
	            // 'licence_state'=> 'required',
				'business_id' => 'required',
			]);
			if ($validator->fails()) {
				$j_errors = $validator->errors();
				$errors = (array) json_decode($j_errors);
				$key = array_key_first($errors);
				return $this->error($errors[$key][0],"",422);
			}

    		//$user_id = Auth::id();
    		//$businessInfo = Helper::getBusinessByUserId($user_id);
    		//if(!empty($businessInfo)){
    			$input = $request->all();
    			$user = Helper::getUserByEmail($input['email']);
    			$password = Str::random(15);

    			if(empty($user)){
					// Add new User
    				$user = User::create([
			            "name" => $input['first_name']." ".$input['last_name'],
			            'first_name' => $input['first_name'],
			            'last_name' => $input['last_name'],
			            'phone' => '+'.$input['dialCode'].preg_replace('/\D+/', '', $input['phone']),
			            'gender' => ucfirst($input['gender']),
			            'email' => $input['email'],
			            'password' => bcrypt($password),
						'address' => $input['address']['complete_address'],
						'country' => $input['address']['country'],
						'city' => $input['address']['city'],
						'state' => $input['address']['state'],
						'zip' => $input['address']['zip'],
						'street' => $input['address']['street'],
						'lat' => $input['address']['lat'],
						'lng' => $input['address']['lng'],
			            'updated_at' => date('Y-m-d H:i:s'),
			            'created_at' => date('Y-m-d H:i:s'),
			        ]);
			        if(!$user){
			        	return $this->error("Something went wrong while adding an employee");
			        }
			        //Send Greetings to User Wellcome to ONDAQ with their username and password
    			}

    			$employeArr  = array(
    				'business_id' => $input['business_id'],
    				'user_id' => $user->id,
    				'role' =>  $input['role'],
    				'profession_id' => $input['profession'],
    				// 'licence' => $input['licence'],
    				// 'licence_state' => $input['licence_state'],
					'product' => $input['product'],
					'service_rendered' => empty($input['render_location']) ? null:$input['render_location'],
    				'status' => $input['status'],
    				'updated_at' => date('Y-m-d H:i:s'),
			        'created_at' => date('Y-m-d H:i:s')
    			);
				if(!empty($input['licence'])){
					$employeArr['licence'] = $input['licence'];
					if (!empty($input['licence_state'])) {
						$employeArr['licence_state'] = $input['licence_state'];
					}
				}

				$employeeAlreadyExist = Helper::getBusinessEmployeeById($input['business_id'],$user->id);
    			//print_r($employeeAlreadyExist);exit;

    			if(!empty($employeeAlreadyExist)){
					$employeAdded = Employee::where('business_id',$input['business_id'])->where('user_id',$user->id)->update($employeArr);
					$employee_id = $employeeAlreadyExist->id;
    			}else{
    				$businessEmployee = Helper::getBusinessEmployees($input['business_id']);
    				$employeeStation = $businessEmployee->toArray();

					if(!empty($employeeStation)){
						$employeeStation[] = array("station_no"=>11);
						$stationId = array_values(array_diff(range(1,max(array_column($employeeStation,'station_no'))),array_column($employeeStation,'station_no')));
					}else{
						$stationId[0] = 1;
					}

					$employeArr['station_no'] = $stationId[0];
    				$employeAdded = Employee::create($employeArr)->id;
					$employee_id = $employeAdded;
    				//Send Email With Verification Link

    			}

    			if($employeAdded){
					// Add Services
					if (!empty($input['services'])) {
						$ifdeleted = Business_service_employee::where('business_id',$input['business_id'])->where('employee_id',$employee_id)->delete();
						//->whereIn('service_id',$input['services'])
						foreach ($input['services'] as $key => $service) {
							if ($service['selected'] === true) {
								$busienssSelectedCate = [
									'business_id' => $input['business_id'],
									'employee_id' => $employee_id,
									'user_id' => $user->id,
									'service_id' => $service['si'],
									'business_service_id' => $service['bsi'],
									'updated_at' => date('Y-m-d H:i:s'),
									'created_at' => date('Y-m-d H:i:s')
								];
								$professionalsServiceInsert = Business_service_employee::create($busienssSelectedCate);
							}
						}
					}

					// Add Employee Hours
					if (!empty($input['schedule'])) {
						foreach ($input['schedule'] as $key => $data) {
							$empHoursArr = [
								'business_id'=>$input['business_id'],
								'employee_id' => $employee_id,
								'title'=>$data['title'],
								'day'=>$data['day'],
								'isOpen'=>$data['isOpen'],
								//'start_time' => $data['start_time']['hours'].":".$data['start_time']['minutes'].":00",
								//'end_time' => $data['end_time']['hours'].":".$data['end_time']['minutes'].":00",
								'isBreak' => $data['isBreak'],
								//'breakStart' => $data['breakStart']['hours'].":".$data['breakStart']['minutes'].":00",
								//'breakEnd' => $data['breakEnd']['hours'].":".$data['breakEnd']['minutes'].":00"
							];
							if ($data['isOpen'] == 1) {
								$empHoursArr['start_time'] = date('H:i:s',strtotime($data['start_time']));
								$empHoursArr['end_time'] = date('H:i:s',strtotime($data['end_time']));
							}else{
								$empHoursArr['start_time'] = NULL;
								$empHoursArr['end_time'] = NULL;
							}
							if ($data['isBreak'] == 1) {
								$empHoursArr['breakStart'] = date('H:i:s',strtotime($data['break_start']));
								$empHoursArr['breakEnd'] = date('H:i:s',strtotime($data['break_end']));
							}else{
								$empHoursArr['breakStart'] = NULL;
								$empHoursArr['breakEnd'] = NULL;
							}
							Employee_hour::create($empHoursArr);
						}
					}
					return $this->success($employee_id);
    			}else{
    				return $this->error("Something went wrong while adding an employee");
    			}

    		// }else{
    		// 	return $this->error("You don't have any business registered at the moment");
    		// }
    	}else{
			return $this->notLogin();
		}
   	}

	public function editEmployee(Request $request)
	{
		if (Auth::check()) {
			$validator = Validator::make($request->all(), [
				// 'first_name' => 'required|string|max:255',
				// 'last_name' => 'required|string|max:255',
				// 'email' => 'required|string|email',
				// 'phone' => 'required|max:20',
				// 'address.complete_address' => 'required',
				// 'dialCode' => 'required',
				'id' => 'required',
				'profession' => 'required',
				'role' => 'required',
				'gender' => 'required|string|max:10',
				'services'=> 'required',
				'render_location'=> 'required',
				// 'licence_state'=> 'required',
				'business_id' => 'required',
			]);
			if ($validator->fails()) {
				$j_errors = $validator->errors();
				$errors = (array) json_decode($j_errors);
				$key = array_key_first($errors);
				return $this->error($errors[$key][0],"",422);
			}

			//$user_id = Auth::id();
			//$businessInfo = Helper::getBusinessByUserId($user_id);
			//if(!empty($businessInfo)){
				$input = $request->all();

				$employeArr  = array(
					//'business_id' => $businessInfo->id,
					//'user_id' => $user->id,
					'product' => $input['product'],
					'role' =>  $input['role'],
					'profession_id' => $input['profession'],
					// 'licence' => $input['licence'],
					// 'licence_state' => $input['licence_state'],
					'service_rendered' => empty($input['render_location']) ? null:$input['render_location'],
					'status' => $input['status'],
					'updated_at' => date('Y-m-d H:i:s')
				);

				if (empty($input['licence'])) {
					$employeArr['licence'] = null;
					$employeArr['licence_state'] = null;
				} else {
					$employeArr['licence'] = $input['licence'];
					$employeArr['licence_state'] = !empty($input['licence_state']) ? $input['licence_state'] : null;
				}
				$employeAdded = Employee::where('id',$input['id'])->where('business_id',$input['business_id'])->update($employeArr);

				if($employeAdded){
					// Delete Assigned Services
					Business_service_employee::where('business_id',$input['business_id'])->where('employee_id',$input['id'])->delete();
					// Add Services
					if (!empty($input['services'])) {
						foreach ($input['services'] as $key => $service) {
							if ($service['selected'] === true) {
								$busienssSelectedCate = [
									'business_id' => $input['business_id'],
									'employee_id' => $input['id'],
									'user_id' => $input['user_id'],
									'service_id' => $service['si'],
									'business_service_id' => $service['bsi'],
									'updated_at' => date('Y-m-d H:i:s'),
									'created_at' => date('Y-m-d H:i:s')
								];
								Business_service_employee::create($busienssSelectedCate);
							}
						}
					}

					// Add Employee Hours
					if (!empty($input['schedule'])) {
						foreach ($input['schedule'] as $key => $data) {
							$empHoursArr = [
								'isOpen'=>$data['isOpen'],
								'isBreak' => $data['isBreak'],
							];
							if ($data['isOpen'] == 1) {
								$empHoursArr['start_time'] = date('H:i:s',strtotime($data['start_time']));
								$empHoursArr['end_time'] = date('H:i:s',strtotime($data['end_time']));
							}else{
								$empHoursArr['start_time'] = NULL;
								$empHoursArr['end_time'] = NULL;
							}
							if ($data['isBreak'] == 1) {
								$empHoursArr['breakStart'] = date('H:i:s',strtotime($data['breakStart']));
								$empHoursArr['breakEnd'] = date('H:i:s',strtotime($data['breakEnd']));
							}else{
								$empHoursArr['breakStart'] = NULL;
								$empHoursArr['breakEnd'] = NULL;
							}
							Employee_hour::where('day',$data['day'])->where('business_id',$input['business_id'])->where('employee_id',$input['id'])->update($empHoursArr);
						}
					}
					return $this->success();
				}else{
					return $this->error("Something went wrong while adding an employee");
				}
			// }else{
			// 	return $this->error("You don't have any business registered at the moment.");
			// }
		}else{
			return $this->notLogin();
		}
	}

	public function employeeDetail($id,$business_id)
	{
		$user_id = Auth::id();
    	//$business = Helper::getBusinessByUserId($user_id);
		$employee = Employee::with('user')->where('id',$id)->where('business_id',$business_id)->first();
		$services = [];
		if (!empty($employee->id)) {
			$services = Business_service_employee::with('business_service')->where('employee_id',$employee->id)->where('business_id',$business_id)->get();
		}else{
			return $this->error("Invalid Employee.");
		}

		// Get Business Hours
		$business_hours = $this->getBusinessHours($business_id,'employee',true);

		// Get Employee Hours
		$e_hours = Employee_hour::where('employee_id',$employee->id)->where('business_id',$business_id)->orderBy('day', 'ASC')->get();

		$employee_hours = [];
		foreach ($e_hours as $key => $eh) {
			if ($business_hours[$key]['status'] == 1) {
				if (!empty($eh['start_time'])) {
					$sTime = $eh['start_time'];
					$eh['start_time'] = date('g:i A',strtotime($sTime));
				}
				if (!empty($eh['end_time'])) {
					$eTime = $eh['end_time'];
					$eh['end_time'] = date('g:i A',strtotime($eTime));
				}
				if (!empty($eh['breakStart'])) {
					$bsTime = $eh['breakStart'];
					$eh['breakStart'] = date('g:i A',strtotime($bsTime));
				}
				if (!empty($eh['breakEnd'])) {
					$beTime = $eh['breakEnd'];
					$eh['breakEnd'] = date('g:i A',strtotime($beTime));
				}
				$eh['hasError'] = false;
				$eh['errorMsg'] = '';
				$eh['active_hours'] = $business_hours[$key]['active_hours'];
				$employee_hours[] = $eh;
			}
		}
		return $this->success(['employee'=>$employee,'services'=>$services,'schedule'=>$employee_hours]);
	}

	// Business Hours
	public function getBusinessHours($business_id,$type='',$returnData=false){
		$data = [];
		$business_hours = Business_hour::where("business_id",$business_id)->orderBy('day', 'ASC')->get();
		if (!empty($business_hours)) {
			if ($type == 'employee') {
				//$business = Helper::getBusinessByUserId($user_id);
				foreach ($business_hours as $key => $bh) {
					$eh = [
						'title' => $bh->title,
						'day' => $bh->day,
						'isOpen' => 0,
						'start_time' => '',
						'end_time' => '',
						'isBreak'=>0,
						'break_start' => '',
						'break_end' => '',
						'hasError'=>false,
						"errorMsg"=>'',
						"status"=>1
					];
					if ($bh->isOpen == 0) {
						$eh['status'] = 0;
					}else{
						$start = strtotime($bh->start_time);
						$end = strtotime($bh->end_time);
						for ($i=$start; $i <= $end ; $i=$i+900) {
							$employeTiming[] = date('g:i a', $i);
						}
						$eh['active_hours'] = $employeTiming;
					}
					$data[] = $eh;
				}
			}else{
				foreach ($business_hours as $key => $value) {
					if (!empty($value['start_time'])) {
						$sTime = $value['start_time'];
						// if ($format == 24) {
						// 	$value['start_time'] = ["hh"=> date('H',strtotime($sTime)),"mm"=> date('i',strtotime($sTime))];
						// }else{
							$value['start_time'] = ["hh"=> date('h',strtotime($sTime)),"mm"=> date('i',strtotime($sTime)),"A"=> date('A',strtotime($sTime))];
						//}
					}else{
						$value['start_time'] = ["hh"=> '',"mm"=> '',"A"=> ''];
					}
					if (!empty($value['end_time'])) {
						$eTime = $value['end_time'];
						// if ($format == 24) {
						// 	$value['end_time'] = ["hh"=> date('H',strtotime($eTime)),"mm"=> date('i',strtotime($eTime))];
						// }else{
							$value['end_time'] = ["hh"=> date('h',strtotime($eTime)),"mm"=> date('i',strtotime($eTime)),"A"=> date('A',strtotime($eTime))];
						//}
					}else{
						$value['end_time'] = ["hh"=> '',"mm"=> '',"A"=> ''];
					}
					$value['hasError'] = false;
					$value['errorMessage'] = "";
					$data[] = $value;
				}
			}
		}
		if ($returnData) {
			return $data;
		}else{
			return $this->success($data);
		}
	}

	public function updateBusinessHours(Request $request)
	{
		if (Auth::check()) {
			$user_id = Auth::id();
			$business = Helper::getBusinessByUserId($user_id);
			if(!empty($business)){
				$data = $request->all();
				foreach ($data as $key => $input) {
					$upd_arr = [];
					if ($input['isOpen'] == 1 && !empty($input['start_time']) && $input['end_time']) {
                        $upd_arr['isOpen'] = 1;
						$upd_arr['start_time'] = date('H:i:s',strtotime($input['start_time']['hh'].':'.$input['start_time']['mm'].' '.$input['start_time']['A']));
						$upd_arr['end_time'] = date('H:i:s',strtotime($input['end_time']['hh'].':'.$input['end_time']['mm'].' '.$input['end_time']['A']));

                        //$upd_arr['start_time'] = date('H:i:s',strtotime($input['start_time']));
                        //$upd_arr['end_time'] = date('H:i:s',strtotime($input['end_time']));
					}else{
                        $upd_arr['isOpen'] = 0;
                        $upd_arr['start_time'] = NULL;
                        $upd_arr['end_time'] = NULL;
					}
					Business_hour::where('id',$input['id'])->where('business_id',$business->id)->update($upd_arr);
				}
				return $this->success("","Successfully updated.");

			}else{
				return $this->error("You don't have any business registered at the moment","",422);
			}
		}else{
			return $this->notLogin();
		}
	}

	public function workingHoursForCalendar($provider,$business_id){
		$data = [];
		if (Auth::check()) {
			if ($provider == 'all') {
				$working_hours = Business_hour::where("business_id",$business_id)->orderBy('day', 'ASC')->get();
			}else{
				$working_hours = Employee_hour::where("business_id",$business_id)->where("employee_id",$provider)->orderBy('day', 'ASC')->get();
			}

			// $user_id = Auth::id();
			// $business = Helper::getBusinessByUserId($user_id);

			if (!empty($working_hours)) {
				foreach ($working_hours as $key => $value) {
					if ($value->isOpen == 1) {
						$day = $value->day;
						if ($value->day == 7) {
							$day = 0;
						}

						if (!empty($value->isBreak) ) {
							$data[] = [
								'daysOfWeek'=> [$day],
								'startTime' => date('H:i',strtotime($value->start_time)),
								'endTime' => date('H:i',strtotime($value->breakStart))
							];
							$data[] = [
								'daysOfWeek'=> [$day],
								'startTime' => date('H:i',strtotime($value->breakEnd)),
								'endTime' => date('H:i',strtotime($value->end_time))
							];
						}else{
							$data[] = [
								'daysOfWeek'=> [$day],
								'startTime' => date('H:i',strtotime($value->start_time)),
								'endTime' => date('H:i',strtotime($value->end_time))
							];
						}
					}
				}
			}
		}
		return $this->success($data);
	}



	// public function getTimeSlots(Request $request){
	// 	$validator = Validator::make($request->all(), [
	// 		'service' => 'required'
	// 	]);
	// 	if ($validator->fails()) {
	// 		$j_errors = $validator->errors();
	// 		$errors = (array) json_decode($j_errors);
	// 		$key = array_key_first($errors);
	// 		return $this->error($errors[$key][0],"",422);
	// 	}
	// 	$param = $request->all();


	// 	if (!empty($param)) {
	// 			$allSlots = [];
	// 			$allProviders = [];
	// 			// Date day
	// 			//$this->load->helper('common_helper');
	// 			$param->date = date('d-m-Y', strtotime($param->date));
	// 			$day = get_day($param->date);

	// 			$already_selected_slots = $this->get_selected_slots($param->businessSlug,'return');
	// 			$this->db->select("businessinformation.lat,businessinformation.lon");
	// 			$this->db->from("businessinformation");
	// 			$this->db->where('userId',$param->business);
	// 			$businessLocInfo = $this->db->get()->row_array();
	// 			// print_r($businessLocInfo);
	// 			// print_r($param);

	// 			// $distanceDiff = $this->distance($businessLocInfo['lat'],$businessLocInfo['lon'],$param->lat,$param->lon,'m');
	// 			// print_r($distanceDiff);
	// 			// exit;

	// 			//print_r($already_selected_slots);exit;
	// 			// Get providers list
	// 			/*if (!empty($already_selected_slots)) {
	// 				$allProviders[] = (array) $already_selected_slots[0]->provider;
	// 			}else{*/
	// 				$this->db->select("person_psn.id,person_psn.firstName, person_psn.lastName, person_psn.gender, person_psn.profile_pic, person_psn.employee_profession,person_psn.service_rendered,person_psn.servingDistance");
	// 				if (!empty($param->deal)) {
	// 					/*if(isset($param->locationprefrence) && !empty($param->locationprefrence)){
	// 						$this->db->where('FIND_IN_SET('.$param->locationprefrence.', person_psn.service_rendered )');
	// 					}*/
	// 					$this->db->where(["person_psn.id"=>$param->deal->dl_provider]);
	// 					$allProviders[] = $this->db->get("person_psn")->row_array();
	// 				}else{
	// 					$this->db->from("person_psn");
	// 					$this->db->where("ssr_service_id",$param->service->ssr_service_id);
	// 					$this->db->where("work_type",'1');
	// 					/*if(isset($param->locationprefrence) && !empty($param->locationprefrence)){
	// 						$this->db->where('FIND_IN_SET('.$param->locationprefrence.', person_psn.service_rendered )');
	// 					}*/
	// 					$this->db->group_start();
	// 					$this->db->where('person_psn.parent',$param->business);
	// 					$this->db->or_where("person_psn.id",$param->business);
	// 					$this->db->group_end();
	// 					$this->db->join("selected_services_ssr","person_psn.id = selected_services_ssr.ssr_userid && selected_services_ssr.ssr_owner = '0' " ,"left");
	// 					$allProviders = $this->db->get()->result_array();
	// 				}

	// 				// print_r($allProviders);
	// 				// exit;
	// 			//}
	// 			// print_r($this->db->last_query());
	// 			// print_r($allProviders); exit;
	// 			if (count($allProviders) > 0) {
	// 				foreach ($allProviders as $key => $provider) {
	// 					$available_slots = [];
	// 					$day_schedule = '';
	// 					// Check employee Vacation

	// 					// if($param->locationprefrence == 2){
	// 					// 	if(isset($param->lat) && isset($param->lon) ){
	// 					// 		$distanceDiff = $this->distance($businessLocInfo['lat'],$businessLocInfo['lon'],$param->lat,$param->lon,'m');
	// 					// 	}else{
	// 					// 		echo json_encode(['condition'=>'error','data'=>"Fill the Adderess field."]); exit;
	// 					// 	}
	// 					// }else{
	// 					// 	$distanceDiff = 0;
	// 					// }

	// 					// if($distanceDiff <= $provider['servingDistance']){

	// 						$this->db->where(["av_date"=>date("Y-m-d",strtotime($param->date)),"av_userId"=>$provider['id'],'av_isOpen'=>'0']);
	// 						$vacation = $this->db->get("availability_av")->row_array();

	// 						if (empty($vacation)) {
	// 							//Check Employee Schedule
	// 							$this->db->where(["eh_day"=>$day,"eh_userid"=>$provider['id'],"eh_isOpen"=>"1"]);
	// 							$day_schedule = $this->db->get("employee_hours_eh")->row_array();

	// 							if (!empty($day_schedule)) {
	// 								// Day Break
	// 								$this->db->where(["ehb_day"=>$day,"ehb_userid"=>$provider['id'],"ehb_isBreak"=>"1"]);
	// 								$break = $this->db->get("employee_hours_breaks_ehb")->row_array();

	// 								// Check Date is today Past or Future?
	// 								// Get Slot start time
	// 								if (date("Y-m-d") == date("Y-m-d",strtotime($param->date))) {


	// 									if (date("Y-m-d H:i:s") > date("Y-m-d H:i:s",strtotime($day_schedule['eh_start_time']))) {
	// 										// Check Currently any booking is start or not
	// 										// print_r(date("i"));
	// 										// if (date("i") > 30 ) {
	// 										// 	$start = (date("H")+1).":00:00";
	// 										// }else {
	// 										// 	$start = date("H").":30:00";
	// 										// }
	// 										 if(date("i") >= 0 && date("i") < 15){
	// 											$start = date("H").":30:00";
	// 										}else if (date("i") >= 15 && date("i") < 30) {
	// 											$start = date("H").":45:00";
	// 										}else if (date("i") > 30 && date("i") < 45   ) {
	// 											$start = (date("H")+1).":00:00";
	// 										}else  {
	// 											$start = (date("H")+1).":15:00";
	// 										}
	// 										// print_r($start);
	// 										// echo "khan";
	// 										$this->db->select("bk_start_time,bk_service_time,bk_end_time");
	// 										$this->db->where(['bk_provider'=>$provider['id'], 'bk_business'=>$param->business, 'bk_date'=>date('Y-m-d',strtotime($param->date))]);
	// 										$this->db->where('bk_start_time <',date("H:i",strtotime($start)));
	// 										$this->db->limit(1);
	// 										$this->db->order_by("bk_id","DESC");
	// 										$booking = $this->db->get("booking_bk")->row_array();
	// 											//echo '<pre>';print_r($booking); exit;
	// 										if (!empty($booking) && date("H:i") < date("H:i", strtotime('+'.$booking["bk_service_time"].' minutes', strtotime($booking["bk_start_time"]))) ) {
	// 											//$start = $booking['bk_start_time'];
	// 											$start = make_fifteen_based_time($booking['bk_end_time']);
	// 										}
	// 									}else{
	// 										$start = $day_schedule['eh_start_time'];
	// 									}
	// 								}elseif(date("Y-m-d") < date("Y-m-d",strtotime($param->date))){
	// 									$start = $day_schedule['eh_start_time'];
	// 								}else{
	// 									echo json_encode(['condition'=>'error','data'=>"Sorry! You choose past date."]);
	// 									exit;
	// 								}


	// 								$day_start = strtotime($start);
	// 								$day_end = strtotime($day_schedule['eh_end_time']);

	// 								for ($i=$day_start; $i <= $day_end; $i=$i+1800) {

	// 									$time = date("H:i:s",$i);
	// 									if (!empty($break)) {
	// 										if($break['ehb_breakStart'] <= $time && $break['ehb_breakEnd'] > $time){
	// 											continue;
	// 										}
	// 									}
	// 									$start_time = date("H:i",$i);

	// 									if (!empty($param->deal)  ) {
	// 										if ($param->deal->dl_totalDuration > 30) {
	// 											$end_time = date("H:i", strtotime('+'.$param->deal->dl_totalDuration.' minutes', $i));
	// 										}else{
	// 											$end_time = date("H:i", strtotime('+30 minutes', $i));
	// 										}
	// 									}else{
	// 										if ($param->service->ssr_duration > 30) {
	// 											$end_time = date("H:i", strtotime('+'.$param->service->ssr_duration.' minutes', $i));
	// 										}else{
	// 											$end_time = date("H:i", strtotime('+30 minutes', $i));
	// 										}
	// 									}

	// 									$this->db->select("booking_bk.*,ssr_gap,ssr_initial_duration,ssr_finish_duration");
	// 									$this->db->where(['bk_provider'=>$provider['id'], 'bk_business'=>$param->business, 'bk_date'=>date('Y-m-d',strtotime($param->date))]);
	// 									$this->db->where('bk_start_time >=',$start_time);
	// 									$this->db->where('bk_start_time <',$end_time);
	// 									$this->db->join("selected_services_ssr","booking_bk.bk_service = selected_services_ssr.ssr_service_id","left");
	// 									$this->db->order_by("bk_service_time","DESC");
	// 									$booking = $this->db->get("booking_bk")->result_array();

	// 									if (!empty($booking)) {
	// 										$newEndTime = date("H:i", strtotime('+'.$booking[0]["bk_service_time"].' minutes', strtotime($booking[0]["bk_start_time"])));
	// 										if (!empty($booking[0]['ssr_gap'])) {
	// 											//Selected service time is less than gap
	// 											if ($booking[0]['ssr_gap'] >= $param->service->ssr_duration) {
	// 												$gap_start = date("H:i", strtotime('+'.$booking[0]['ssr_initial_duration'].' minutes', strtotime($booking[0]["bk_start_time"])));
	// 												$gap_end = date("H:i", strtotime('+'.$booking[0]['ssr_gap'].' minutes', strtotime($gap_start)));
	// 												$this->db->select("booking_bk.*,ssr_gap,ssr_initial_duration,ssr_finish_duration");
	// 												$this->db->where(['bk_provider'=>$provider['id'], 'bk_business'=>$param->business, 'bk_date'=>date('Y-m-d',strtotime($param->date))]);
	// 												$this->db->where('bk_start_time >=',$gap_start);
	// 												$this->db->where('bk_start_time <',$gap_end);
	// 												$this->db->join("selected_services_ssr","booking_bk.bk_service = selected_services_ssr.ssr_service_id","left");
	// 												$this->db->order_by("bk_service_time","DESC");
	// 												$booking2 = $this->db->get("booking_bk")->result_array();
	// 												if (empty($booking2)) {
	// 													$available_slots[] = date("h:i A",strtotime($gap_start));
	// 												}
	// 											}
	// 										}
	// 										$newEndTime = make_fifteen_based_time($newEndTime);
	// 										$aa = date("H:i", strtotime('-30 minutes', strtotime($newEndTime)));
	// 										$i = strtotime($aa);
	// 									}else{

	// 										if (!empty($already_selected_slots)) {
	// 											usort($already_selected_slots, function($a, $b) {
	// 											    if ($a->time_slot == $b->time_slot) {
	// 											        return 0;
	// 											    }
	// 											    return ($a->time_slot < $b->time_slot) ? -1 : 1;
	// 											});
	// 											$status = 0;
	// 											foreach ($already_selected_slots as $key => $value) {
	// 												if (!empty($value->deal)) {
	// 													$already_selected_service_detail = ['ssr_duration'=>$value->deal->dl_totalDuration];

	// 													/*$this->db->select("dl_totalDuration as ssr_duration")->where(["dl_id"=>$value->deal->dl_id,"dl_provider"=>$provider['id']]);
	// 													$already_selected_service_detail = $this->db->get("deals_dl")->row_array();*/
	// 												}else{
	// 													$already_selected_service_detail = [
	// 														'ssr_duration'=>$value->service->ssr_duration,
	// 														'ssr_initial_duration'=>$value->service->ssr_initial_duration,
	// 														'ssr_gap'=>$value->service->ssr_gap,
	// 														'ssr_finish_duration'=>$value->service->ssr_finish_duration,
	// 													];
	// 													/*$this->db->select("ssr_duration,ssr_initial_duration,ssr_gap,ssr_finish_duration")->where(["ssr_service_id"=>$value->service,"ssr_userid"=>$value->provider]);
	// 													$already_selected_service_detail = $this->db->get("selected_services_ssr")->row_array();*/
	// 												}
	// 												if (date("H:i",strtotime($value->time_slot)) >= $start_time && date("H:i",strtotime($value->time_slot)) < $end_time) {
	// 													$newEndTime = date("H:i", strtotime('+'.$already_selected_service_detail["ssr_duration"].' minutes', strtotime($value->time_slot)));
	// 													if (!empty($already_selected_service_detail['ssr_gap'])) {
	// 														if ($already_selected_service_detail['ssr_gap'] >= $param->service->ssr_duration) {
	// 															$gap_start = date("h:i A", strtotime('+'.$already_selected_service_detail['ssr_initial_duration'].' minutes', strtotime($value->time_slot)));
	// 															$isEqual = 0;
	// 															foreach ($already_selected_slots as $key => $aVal) {
	// 																if ($aVal->time_slot == $gap_start) {
	// 																	$isEqual = 1;
	// 																	break;
	// 																}
	// 															}
	// 															if ($isEqual == 0) {
	// 																$available_slots[] = $gap_start;
	// 															}
	// 														}
	// 													}
	// 													$newEndTime = make_fifteen_based_time($newEndTime);
	// 													$aa = date("H:i", strtotime('-30 minutes', strtotime($newEndTime)));
	// 													$status = 1;
	// 													$i = strtotime($aa);
	// 													break;
	// 												}
	// 											}

	// 											//end for eachs

	// 											if ($status == 0) {
	// 												$available_slots[] = date("h:i A",strtotime($start_time));
	// 											}
	// 										}else{
	// 											$available_slots[] = date("h:i A",strtotime($start_time));
	// 										}
	// 									}

	// 								}


	// 								//$six_month_array = make_six_month_array($business_hours,$param->date);
	// 								//$selected_service_detail = $this->selected_service_detail($param);

	// 								if (count($available_slots) > 0) {
	// 									$allSlots[] = ['slots'=>$available_slots,'provider'=>$provider];
	// 								}else{
	// 									//echo json_encode(['condition'=>'error','data'=>"Employee is not available."]);
	// 								}

	// 							}else{
	// 								//echo json_encode(['condition'=>'error','data'=>"Employee is not operating today."]);
	// 							}

	// 						}else{
	// 							//echo json_encode(['condition'=>'error','data'=>"Business will be off that day."]); exit;
	// 						}

	// 					// }else{
	// 					// 	unset($key);
	// 					// }
	// 				}

	// 				/*if($param->locationprefrence == 1){
	// 					if (empty($allSlots)) {
	// 						echo json_encode(['condition'=>'error','data'=>"Sorry! No Time Slots are available."]); exit;
	// 					}
	// 				}else{
	// 					if (empty($allSlots)) {
	// 						echo json_encode(['condition'=>'error','data'=>"No Employee is available"]); exit;
	// 					}
	// 				}*/


	// 				echo json_encode(['condition'=>'success','data'=>$allSlots,'date'=>date('l, M d, Y (T)',strtotime($param->date)) ]);
	// 			}else{
	// 				echo json_encode(['condition'=>'error','data'=>"Sorry! No Employee is available"]);
	// 			}

	// 	}else{
	// 		echo json_encode(['condition'=>'error','data'=>"Empty data! Please select service and date."]);
	// 	}






	// }

	public function gatPaymentCards(){
		if (Auth::check()) {
			$user_id = Auth::id();
			return $this->success(Stripe_account::where('user_id',$user_id)->where('status',1)->get());
		}else{
			return $this->success([]);
		}
	}





	public function getTimeSlots(Request $request){
		$validator = Validator::make($request->all(), [
			//'services' => 'required',
			'date' => 'required',
			'business' => 'required'
		]);
		if ($validator->fails()) {
			$j_errors = $validator->errors();
			$errors = (array) json_decode($j_errors);
			$key = array_key_first($errors);
			return $this->error($errors[$key][0],"",422);
		}
		$param = $request->all();
		$businessInfo = Helper::getBusinessBySlug($param['business']);
		$businessDateTimeZone = Helper::convertToTimeZone($businessInfo->timeZone); 

		// Get Day
		$param['date'] = date('d-m-Y', strtotime($param['date']));
		$bookingDay = Helper::get_day($param['date']);
		$data = [];
		$bookingavailabilityDate = "";
		if (!empty($param['deal'])) {
			// Validate Deal
			if (empty($param['deal'])) {
				return $this->error("Deal is empty.","",422);
			}

			// Get Employee
			$employee = Employee::with('user')->with('profession')
			->with(['employeeHours' => function ($query) use ($bookingDay){
            	$query->where('day', '=', $bookingDay);
        	}])->where('business_id',$businessInfo->id)
			->where('status',1)
			->where('id',$param['deal']['employee_id'])
			->first();
			if (empty($employee)) {
				return $this->error("No employee found of this deal.","",422);
			}

			// Employee Hours
			$employee_hours = $employee->employeeHours[0];
			unset($employee->employeeHours);

			// Deal Detail
			$employee->deal = $param['deal'];

			// Get Slots
			$slots = $this->getAvailableSlots($employee,$employee_hours,$param['date'],true,false,$businessDateTimeZone);
			if (!empty($slots)) {
				$employee->available_slots = $slots;
				$data[] = $employee;
			}
		}else{
			// Validate Services
			if (empty($param['services'])) {
				return $this->error("Services is empty.","",422);
			}

			// Get Employee
			$employees = Employee::with('user')->with('profession')
			->with(['employeeHours' => function ($query) use ($bookingDay){
            	$query->where('day', '=', $bookingDay);
        	}])
			->where('business_id',$businessInfo->id)
			->where('status',1)
			->where(function ($query) use ($param,$businessInfo){
				$query->select(DB::raw("COUNT(*)"))
				->from('business_service_employees')
				->where('business_id',$businessInfo->id)
				->whereColumn('business_service_employees.employee_id','employees.id')
				->where(function ($query) use ($param){
					foreach ($param['services'] as $key => $serv) {
						if ($key == 0) {
							$query->where('service_id',$serv['id']);
						}else{
							$query->orWhere('service_id',$serv['id']);
						}
					}
				});
			},count($param['services']))->get();

			if (!empty($employees) && count($employees) > 0) {
				foreach ($employees as $key => $employee) {
					// Employee Hours
					$employee_hours = $employee->employeeHours[0];
					unset($employee->employeeHours);

					// Services Detail
					$employee->services = $param['services'];

					// Get Slots
					$slots = $this->getAvailableSlots($employee,$employee_hours,$param['date'],false,false,$businessDateTimeZone);
					if (!empty($slots)) {
						$employee->available_slots = $slots;
						$data[] = $employee;
					}
				}
			}
			//if no employee has availabilty get the next availability date
			if (empty($data)) {
				//get next date
				$date = date('Y-m-d', strtotime($param['date'])); //booking date param
				for($i =1; $i <= 7; $i++){

				    $nextBookingDate = date('Y-m-d', strtotime("+$i day", strtotime($date)));
				    $nextBookingDay = Helper::get_day($nextBookingDate);

				    //get available employees
				    $employees = Employee::with('user')->with('profession')
					->with(['employeeHours' => function ($query) use ($nextBookingDay){
		            	$query->where('day', '=', $nextBookingDay);
		        	}])
					->where('business_id',$businessInfo->id)
					->where('status',1)
					->where(function ($query) use ($param,$businessInfo){
						$query->select(DB::raw("COUNT(*)"))
						->from('business_service_employees')
						->where('business_id',$businessInfo->id)
						->whereColumn('business_service_employees.employee_id','employees.id')
						->where(function ($query) use ($param){
							foreach ($param['services'] as $key => $serv) {
								if ($key == 0) {
									$query->where('service_id',$serv['id']);
								}else{
									$query->orWhere('service_id',$serv['id']);
								}
							}
						});
					},count($param['services']))->get();

					if (!empty($employees) && count($employees) > 0) {
						foreach ($employees as $key => $employee) {
							// Employee Hours
							$employee_hours = $employee->employeeHours[0];
							unset($employee->employeeHours);

							// Services Detail
							$employee->services = $param['services'];

							// Get Slots
							$slots = $this->getAvailableSlots($employee,$employee_hours,$param['date'],false,true,$businessDateTimeZone);
							if (!empty($slots)) {
								$bookingavailabilityDate = $nextBookingDate;
								break 2;
							}
							$bookingavailabilityDate = "";
						}
					}


				}

				//return $this->success($weekOfdays);
			}
        }
        $response["data"] = $data;
        $response["nextAvailableDate"] = $bookingavailabilityDate;
		return $this->success($response);
	}

	public function getAvailableSlots($employee,$employee_hours,$date,$is_deal=false, $nextAvailability = false,$timeZone){
		$available_slots = [];

		if(isset($employee_hours->isOpen) && $employee_hours->isOpen == 1){
			$employeeStartTime = $employee_hours->start_time;
			$employeeEndTime = $employee_hours->end_time;
			$businessDateZone = $timeZone["date"];
			$businessTimeZone = $timeZone["time"];
			$businessDateTime = $timeZone["datetime"];
			$businessDateTimeHI = $timeZone["hourMinues"];
			$businessDateTimeMins = $timeZone["minutes"];
			if(!$nextAvailability && strtotime($businessDateZone) == strtotime($date) ) 
			{
				if(strtotime($employeeEndTime) < strtotime($businessTimeZone))
				{
					return [];
				}

				if(strtotime($employeeStartTime) < strtotime($businessTimeZone) ) 
				{
					$d = strtotime($businessDateTimeHI);
					$minutes = date($businessDateTimeMins);
					if($minutes <= 15 &&  $minutes > 0 )
					{
						$d = $this->roundToClosestMinute($d,"30","ceil");
						//30
					}
					else if($minutes <= 30 && $minutes > 15)
					{
						$d = $this->roundToClosestMinute($d,"15","ceil");
						//45
					}
					else if($minutes <= 45 &&  $minutes > 30)
					{
						$d = $this->roundToClosestMinute($d,"30","ceil");
						//00
					}
					else
					{
						$d = $this->roundToClosestMinute($d,"15","ceil");
						//15
					}
					
					$employeeStartTime = date("H:i:s",$d);
				}
			}
			$opening_hours   = [[$employeeStartTime,$employeeEndTime]];
			if($employee_hours->isBreak == 1){
				$employeeLunchStartTime = $employee_hours->breakStart;
				$employeeLunchEndTime = $employee_hours->breakEnd;
				$opening_hours   = [[$employeeStartTime,$employeeLunchStartTime],[$employeeLunchEndTime,$employeeEndTime]];
			}
			$pendingBookings = Booking::where('provider_id',$employee->user_id)->whereDate('booking_date',date('Y-m-d',strtotime($date)))->get();
			$occupied_slots = [];
			foreach ($pendingBookings as $bookings) {
				$occupied_slots[]  = [$bookings->booking_start_time,$bookings->booking_end_time];
			}
			$valid_timeslots = [];
			$oph = $opening_hours;
			foreach($occupied_slots as $os) {
				$oph = $this->flatAndClean($this->cutOpeningHours($oph, $os));
			}
			$valid_timeslots = $oph;

			foreach ($valid_timeslots as  $value) {
				$array = $this->getSlotsBetweenTime($value[0],$value[1]);
				$available_slots = array_merge($available_slots,$array);
			}
		}

		$finalSlots = [];
		foreach ($available_slots as  $value) {
			$hour = date('H',strtotime($value));
			$dayTerm = ($hour > 16) ? "Evening" : (($hour > 11) ? "Afternoon" : "Morning");
			$finalSlots[$dayTerm][] = $value;
		}
		return $finalSlots;
	}


	function getSlotsBetweenTime($startTime,$endTime){
		if(!empty($startTime) && !empty($endTime)){
			$period = new CarbonPeriod($startTime, '15 minutes', $endTime); // for create use 24 hours format later change format
			$allSlots = [];
			foreach($period as $item){
			    array_push($allSlots,$item->format("g:i A"));
			}

			return $allSlots;
		}

	}

	// function availableSlots($duration, $cleanup, $start, $end, $break_start, $break_end) {
	//     $start         = new DateTime($start);
	//     $end           = new DateTime($end);
	//     $break_start           = new DateTime($break_start);
	//     $break_end           = new DateTime($break_end);
	//     $interval      = new DateInterval("PT" . $duration . "M");
	//     $cleanupInterval = new DateInterval("PT" . $cleanup . "M");
	//     $slots = array();

	//     for ($intStart = $start; $intStart < $end; $intStart->add($interval)->add($cleanupInterval)) {
	//         $endPeriod = clone $intStart;
	//         $endPeriod->add($interval);

	//         if(strtotime($break_start->format('H:i A')) < strtotime($endPeriod->format('H:i A')) && strtotime($endPeriod->format('H:i A')) < strtotime($break_end->format('H:i A'))){
	//             $endPeriod = $break_start;
	//             $slots[] = $intStart->format('H:i A') . ' - ' . $endPeriod->format('H:i A');
	//             $intStart = $break_end;
	//             $endPeriod = $break_end;
	//             $intStart->sub($interval);
	//         }else{
	//             $slots[] = $intStart->format('H:i A') . ' - ' . $endPeriod->format('H:i A');
	//         }
	//     }


	//     return $slots;
	// }




	function timeToNum($time) {
	    preg_match('/(\d\d):(\d\d)/', $time, $matches);
	    return 60*$matches[1] + $matches[2];
 	}

	function numToTime($num) {
	    $m  = $num%60;
	    $h = intval($num/60) ;
	    return ($h>9? $h:"0".$h).":".($m>9? $m:"0".$m);

	}

	function  roundToClosestMinute($input = 0, $round_to_minutes = 5, $type = 'auto')
	{
		$now = !$input ? time() : (int)$input;
	
		$seconds = $round_to_minutes * 60;
		$floored = $seconds * floor($now / $seconds);
		$ceiled = $seconds * ceil($now / $seconds);
	
		switch ($type) {
			default:
				$rounded = ($now - $floored < $ceiled - $now) ? $floored : $ceiled;
				break;
	
			case 'ceil':
				$rounded = $ceiled;
				break;
	
			case 'floor':
				$rounded = $floored;
				break;
		}
	
		return $rounded ? $rounded : $input;
	}

	// substraction interval $b=[b0,b1] from interval $a=[a0,a1]
 	function sub($a,$b) {
		// case A: $b inside $a
		if($a[0]<=$b[0] and $a[1]>=$b[1]) return [ [$a[0],$b[0]], [$b[1],$a[1]] ];

		// case B: $b is outside $a
		if($b[1]<=$a[0] or $b[0]>=$a[1]) return [ [$a[0],$a[1]] ];

		// case C: $a inside $b
		if($b[0]<=$a[0] and $b[1]>=$a[1]) return [[0,0]]; // "empty interval"

		// case D: left end of $b is outside $a
		if($b[0]<=$a[0] and $b[1]<=$a[1]) return [[$b[1],$a[1]]];

		// case E: right end of $b is outside $a
		if($b[1]>=$a[1] and $b[0]>=$a[0]) return [[$a[0],$b[0]]];
 	}

	 // flat array and change numbers to time and remove empty (zero length) interwals e.g. [100,100]
	 // [[ [167,345] ], [ [433,644], [789,900] ]] to [ ["07:00","07:30"], ["08:00","08:30"], ["09:00","09:30"] ]
	 // (number values are not correct in this example)
	function flatAndClean($interwals) {
	     $result = [];
	     foreach($interwals as $inter) {
	         foreach($inter as $i) {
	             if($i[0]!=$i[1]) {
	                 //$result[] = $i;
	                 $result[] = [$this->numToTime($i[0]), $this->numToTime($i[1])];
	             }
	         }
	     }
	     return $result;
	}

	 // calculate new_opening_hours = old_opening_hours - occupied_slot
	function cutOpeningHours($op_h, $occ_slot) {
	    foreach($op_h as $oh) {
	        $ohn = [$this->timeToNum($oh[0]), $this->timeToNum($oh[1])];
	        $osn = [$this->timeToNum($occ_slot[0]), $this->timeToNum($occ_slot[1])];
	        $subsn[] = $this->sub($ohn, $osn);
	    }
	    return $subsn;
	}

	public function getServicesEmployees(Request $request)
	{
		$param = $request->all();

		// Validate Services
		if (empty($param['services']) || count($param['services']) == 0) {
			return $this->error("Services is empty.","",422);
		}
		$services = [];
		foreach ($param['services'] as $key => $serv) {
			if ($serv['selected'] === true) {
				$services[] = $serv;
			}
		}
		$employees = [];
		if (count($services) > 0) {
			// Get Employee ->with('profession')
			$employees = Employee::with('user')
			->where('business_id',$services[0]['business_id'])
			->where('status',1)
			->where(function ($query) use ($services){
				$query->select(DB::raw("COUNT(*)"))
				->from('business_service_employees')
				->where('business_id',$services[0]['business_id'])
				->whereColumn('business_service_employees.employee_id','employees.id')
				->where(function ($query) use ($services){
					foreach ($services as $key => $serv) {
						if ($key == 0) {
							$query->where('service_id',$serv['service_id']);
						}else{
							$query->orWhere('service_id',$serv['service_id']);
						}
					}
				});
			},count($services))->get();
		}
		return $this->success($employees);
	}

	public function est_waiting_time($id){

		$booking_info = Booking::where('provider_id',$id)->whereDate('booking_date',date('Y-m-d'))->where("booking_status",0)->get();
		
		if (count($booking_info) > 0) {
			$B_time = 0;
			foreach ($booking_info as $key => $value) {
				$B_time += $value['booking_duration'];
			}
		}else{
			return 5;
		}
		
		if ($B_time > 0) {
			$end = date("Y-m-d H:i:s", strtotime('+'.$B_time.' minutes'));
			$to_time = strtotime($end);
			$from_time = strtotime(date('Y-m-d H:i:s'));
			$diff = round(abs($to_time - $from_time) / 60,2);
			if ($diff < 5 && $diff >= 0) {
				return 5;
			}elseif ($diff > 5) {
				return $diff;
			}else{
				return 0;
			}
		}else{
			return 5;
		}
	}

	function convertToHoursAndMinutes($minutes) {
		if ($minutes < 60) {
		  return $minutes . " min";
		} else {
		  $hours = floor($minutes / 60);
		  $remainingMinutes = $minutes % 60;
	  
		  $result = $hours . "h";
		  if ($remainingMinutes > 0) {
			$result .= " " . $remainingMinutes . " min";
		  }
	  
		  return $result;
		}
	  }

}