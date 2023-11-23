<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use App\Traits\ApiResponser;
use App\Helpers\Helper;
use App\Models\Deal;
use App\Models\Employee;
use App\Models\Business;
use App\Models\Deal_service;
use App\Traits\Aws;
use Stevebauman\Location\Facades\Location;

// use Illuminate\Http\File;
// use Illuminate\Support\Facades\Storage;

class DealsController extends Controller
{
    use ApiResponser;
    use Aws;
	public function dealsListing(Request $request){
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
		
        $query = Deal::select('deals.*', 'businesses.title as b_title', 'businesses.city','businesses.lat','businesses.lng')
        ->leftJoin('businesses', 'businesses.id', '=', 'deals.business_id')
        ->leftJoin('users', 'users.id', '=', 'deals.user_id')
        ->whereDate('startDate','<=', Carbon::now())
        ->whereDate('endDate','>=', Carbon::now())
        ->where('deals.status',1);

		// $query = Business::select("businesses.*",
		// 	DB::raw("(SELECT CHAR_LENGTH(ROUND(MIN(cost),0)) FROM business_services WHERE business_id = businesses.id AND status = 1) as min_price_length"),
		// 	DB::raw("(SELECT CHAR_LENGTH(ROUND(MAX(cost),0)) FROM business_services WHERE business_id = businesses.id AND status = 1) as max_price_length"),
		// 	DB::raw("(SELECT ROUND(AVG(overall_rating),0) FROM reviews WHERE business_id = businesses.id AND is_deleted = 0) as rating"),
		// 	DB::raw("(SELECT COUNT(*) FROM reviews WHERE business_id = businesses.id AND is_deleted = 0) as total_reviews"),
		// 	DB::raw("(select COUNT(*) from business_hours where business_hours.business_id = businesses.id and day = ".$day." and isOpen = 1 and time(start_time) < '".date('H:i:s')."' and time(end_time) > '".date('H:i:s')."') as is_open")
		// )->with("categories:id,title")->where('status',1);
		// $query->with('gallery');

		// Location
		$query->where('businesses.city','like','%'.$location[0].'%');
		if (!empty($location[1])) {
			$query->where('businesses.state',$location[1]);
		}

		// Service
		// if (!empty($input['service'])) {
		// 	$srevice_str = str_replace('+',' ',$input['service']);
		// 	$service = DB::table("services")->where('title','like',$srevice_str)->first();
		// 	if (!empty($service)) {
		// 		$query->whereHas('businessServices', function (Builder $q) use ($service) {
		// 			$q->where('service_id',$service->id);
		// 		},'>', 0);
		// 	}
		// }

		// Render Location
		// $render_str = trim($input['render']);
		// if (empty($input['render'])) {
		// 	$render_str = 'business';
		// }
		// $query->where('businesses.service_render_location','like','%'.$render_str.'%');

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
			$query->whereBetween("discounted_price",$price_limit);
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
			$query->where('users.gender',$gender);
		}

		// Distance
		if (!empty($currentUserLat) && !empty($currentUserLng) && !empty($input['distance'])) {
			$query->whereRaw('(ST_Distance_Sphere(point(businesses.lng, businesses.lat),point('.$currentUserLng.', '.$currentUserLat.'))*.000621371192) <= '.$input['distance']);
			// $query->having('distance',"<=",$input['distance']);
		}

		// Order By
		// $orderBy = $input['sort_by'];
		// if (empty($input['sort_by'])) {
		// 	$orderBy = "featured";
		// }
		// if ($orderBy === 'featured') {
		// 	$query->orderBy('is_featured', 'DESC');
		// }elseif ($orderBy === 'new') {
		// 	$query->orderBy('id', 'DESC');
		// }elseif ($orderBy === 'top_rated') {
		// 	$query->orderBy('rating', 'DESC');
		// }

		$data = $query->simplePaginate($input['limit']);
		return $this->success($data);
	}
    public function getDeals(Request $request){
        $q = Deal::select('deals.*', 'businesses.title as b_title', 'businesses.city','lat','lng')
        ->leftJoin('businesses', 'businesses.id', '=', 'deals.business_id')
        ->whereDate('startDate','<=', Carbon::now())
        ->whereDate('endDate','>=', Carbon::now())
        ->where('deals.status',1)
        ->limit(8);

        $query = $q;
		if ($request->has('location') && !empty($request->location)) {
			$location = explode("-",$request->location);
			$query->where('businesses.city','like','%'.$location[0].'%');
		}

        $deals = $query->get();
        return $this->success($deals);

    }
    public function homeDeals(Request $request){
        $q = Deal::select('deals.*', 'businesses.title as b_title', 'businesses.city','lat','lng')
        ->leftJoin('businesses', 'businesses.id', '=', 'deals.business_id')
        ->whereDate('startDate','<=', Carbon::now())
        ->whereDate('endDate','>=', Carbon::now())
        ->where('deals.status',1)
        ->limit(8);

        $query = $q;
		if ($request->has('location') && !empty($request->location)) {
			$location = explode("-",$request->location);
			$query->where('businesses.city','like','%'.$location[0].'%');
		}

        $deals = $query->get();

		if (empty($deals) || count($deals) == 0) {
			$deals = $q->get();
		}

        		// Get Distance
		if (!empty($deals)) {

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

			foreach($deals as $key => $deal)
			{

				$dealLat = !empty($deal->lat)?$deal->lat:"";
				$dealLng = !empty($deal->lng)?$deal->lng:"";
				$deals[$key]['distance'] = Helper::getDistance($currentUserLat,$currentUserLng,$dealLat,$dealLng);
			}
		}
        return $this->success($deals);
    }

    public function dtDealsList(Request $request){
        $input = $request->all();
        $user_id = Auth::id();
    	$business = Helper::getBusinessByUserId($user_id);
        $order_columns = ['id','created_at','title',null,null,null,'startDate','endDate','status'];

        $deal_query = $this->deals_query($business->id,$input);

        // Order By
        if (isset($input['order'][0]['column']) && isset($order_columns[$input['order'][0]['column']])) {
            $deal_query->orderBy($order_columns[$input['order'][0]['column']],$input['order'][0]['dir']);
        }else{
            $deal_query->orderBy('id', 'DESC'); 
        }

        // Offset, Limit
        if ($input['start'] >= 0  && $input['length'] >= 0) {
            $deal_query->offset($input['start']);
            $deal_query->limit($input['length']);
        }

        $deals = $deal_query->get();
        $data = [];
        if(!empty($deals) && count($deals) > 0){
            foreach ($deals as $key => $deal) {
                $output = [];
                $output[] = "#".$deal->id;
                //$output[] = date('m/d/Y',strtotime($deal->created_at));
                if (!empty($input['platform'])) {
                    if ($input['platform'] == 'mob') {
                        $output[] = "https://s3.us-east-2.amazonaws.com/images.ondaq.com/deals/thumbnail/default.jpg";
                    }
                }else{
                    if (!empty($deal->banner)) {
                        $output[] = '<img class="img-fluid banner-img" alt="" src="'.$deal->banner.'" >';
                    }else{
                        $output[] = '<img class="img-fluid banner-img" alt="" src="https://s3.us-east-2.amazonaws.com/images.ondaq.com/deals/thumbnail/default.jpg" >';
                    }
                }
                
                $output[] = $deal->title;

                if (!empty($input['platform'])) {
                    if ($input['platform'] == 'mob') {
                        $output[] = $deal->user->picture;
                        $output[] = $deal->user->name;
                    }
                }else{
                    // if (!empty($deal->user->picture)) {
                    //     $img = '<img class="img-fluid" src="'.$deal->user->picture.'" alt="">';
                    // }else{
                    //     $img = '<img class="img-fluid" src="https://autolinkme.com/uploads/crmassets/profile.svg" alt="">';
                    // }
                    $img = '<img class="img-fluid" src="https://s3.us-east-2.amazonaws.com/images.ondaq.com/profile/profile.svg" alt="">';
                    $output[] = '<a class="manname" href="javascript:void(0)">'.$img.' '.$deal->user->name.' </a>';
                }
                // Discount
                if ($deal->unit == 'percent') {
                    $discount = $deal->discount_value.'%';
                }else{
                    $discount = '$'.$deal->discount_value;
                }
                $output[] = $discount;
                // Services
                $services = '';
                if (count($deal->deal_services) > 0) {
                    foreach ($deal->deal_services as $key => $serv) {
                        if ($key > 0) {
                            $services .= ', ';
                        }
                        $services .= $serv->service->title;
                    }
                }
                $output[] = $services;


                $output[] = date('m/d/Y',strtotime($deal->startDate));
                $output[] = date('m/d/Y',strtotime($deal->endDate));

                // Status
                if (!empty($input['platform'])) {
                    if ($input['platform'] == 'mob') {
                        $output[] = $deal->status;
                    }
                }else{
                    if ($deal->status == 1) {
                        $output[] = '<a class="solds change-status" href="#" data-slug="'.$deal->slug.'" data-status="'.$deal->status.'">Active</a>';
                    }else{
                        $output[] = '<a class="losts change-status" href="#" data-slug="'.$deal->slug.'" data-status="'.$deal->status.'">Inactive</a>';
                    }
                }
                // Action
                if (empty($input['platform'])) {
                    //$output[] = '<a class="opens edit-deal" href="edit-deal/'.$deal->slug.'">Edit</a>';
                    $output[] = '<a class="opens edit-deal" data-slug="'.$deal->slug.'" href="javascript:void(0)"><img alt="" src="https://s3.us-east-2.amazonaws.com/images.ondaq.com/icons/pencil.svg" />Edit</a>';
                }
                $data[] = $output;
            }
        }


        if (!empty($input['platform'])) {
            if ($input['platform'] == 'mob') {
                $outsfsput = [
                    'recordsTotal'=> $this->all_deals_count($business->id),
                    'recordsFiltered'=> $this->filtered_deals_count($business->id,$input),
                    "data"=>$data
                ];
            }
        }else{
            $outsfsput = [
                "draw"=>$input['draw'],
                'recordsTotal'=> $this->all_deals_count($business->id),
                'recordsFiltered'=> $this->filtered_deals_count($business->id,$input),
                "data"=>$data
            ];
        }
        return response()->json($outsfsput, 200);
    }


    public function deals_query($business_id,$input){
        $q = Deal::with("user:id,name,picture")->with("deal_services.service:id,title")->where("business_id",$business_id);
        if (!empty($input['search']['value'])) {
            $q->where('title','LIKE','%'.$input["search"]["value"].'%');
            //$search_val = "%".$input["search"]["value"]."%";
            // $query->where(function ($query) use ($search_val) {
            //     $query->where('lead_sources.ls_name', 'like', $search_val)
            //     ->orWhere('customers.c_first_name', 'like', $search_val)
            //     ->orWhere('customers.c_last_name', 'like', $search_val);
            // });
        }
        if (!empty($input['user_id'])) {
            $q->where('user_id',$input['user_id']);
        }
        return $q;
    }
    public function all_deals_count($business_id){
        return $q = Deal::where("business_id",$business_id)->count();
    }
    public function filtered_deals_count($business_id,$input){
        $query = $this->deals_query($business_id,$input);
        return $query->count();
    }

    public function dealDetail($slug)
	{
        $q = Deal::with('business')->with('deal_services.service')->with('deal_services.business_service')->where('slug',$slug);
        if (Auth::check()) {
            $user_id = Auth::id();
            $business = Helper::getBusinessByUserId($user_id);
            $q->where('business_id',$business->id);
        }
        $deal = $q->first();
        return $this->success($deal);
	}

    public function addNewDeal(Request $request){
        if (Auth::check()) {
            $validator = Validator::make($request->all(), [
                'title' => 'required|string|max:255',
                'start_date' => 'required',
                'description' => 'required',
                'employee' => 'required',
                'services' => 'required',
                'discount_type' => 'required|string',
                'discount' => 'required|integer',
                'expire_date' => 'required',
                'business_id' => 'required',
                'banner' => 'required',
            ]);
            if ($validator->fails()) {
                $j_errors = $validator->errors();
                $errors = (array) json_decode($j_errors);
                $key = array_key_first($errors);
                return $this->error($errors[$key][0],"",422);
            }

            $input = $request->all();
            $user_id = Auth::id();
            $business_id = $input['business_id'];
            //$businessInfo = Helper::getBusinessByUserId($user_id);
            if(!empty($business_id)){
                $total_price = 0;
                $total_duration = 0;
                $discounted_price = 0;

                for ($i=0; $i < count($input['services']); $i++) { 
                    $total_price = $total_price + $input['services'][$i]['cost'];
                    $total_duration = $total_duration + $input['services'][$i]['duration'];
                }

                if ($input['discount_type'] == 'percent') {
                    $discount = ($total_price * $input['discount']) / 100;
                    $discounted_price = $total_price - $discount;
                }else{
                    $discounted_price = $total_price - $input['discount'];
                }

                $package = $input['package'] == true ? 1:0;

                if(!empty($input['banner'])){
                    $banner = $this->AWS_FileUpload('base64', $input['banner'],'deals');
                }
                // Add new Deal
                $deal = Deal::create([
                    'business_id' => $business_id,
                    'user_id' => $input['employee']['user']['id'],
                    'employee_id' => $input['employee']['id'],
                    "title" => $input['title'],
                    // 'slug' => Str::slug($input['title']),
                    'description' => $input['description'],
                    'banner' => (!empty($banner)?$banner:null),
                    'startDate' => $input['start_date'],
                    'endDate' => $input['expire_date'],
                    'unit' => $input['discount_type'],
                    'discount_value' => $input['discount'],
                    'total_price' => $total_price,
                    'discounted_price' => $discounted_price,
                    'total_duration' => $total_duration,
                    'is_package' => $package,
                    'status' => $input['status'],
                    'updated_at' => date('Y-m-d H:i:s'),
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
                if(!$deal){
                    return $this->error("Something went wrong while adding a deal");
                }

                // Add Deal Services
                for ($i=0; $i < count($input['services']); $i++) { 
                    if ($input['services'][$i]['selected'] == true) {
                        $deal_service = Deal_service::create([
                            'deal_id' => $deal->id,
                            'service_id' => $input['services'][$i]['service_id'],
                            "business_service_id" => $input['services'][$i]['business_service_id'],
                            'status' => 1
                        ]);
                    }
                }
                if(!$deal_service){
                    return $this->error("Something went wrong while adding deal services");
                }else{
                    return $this->success();
                }               
            }else{
                return $this->error("You don't have any business registered at the moment");
            }
        }else{
            return $this->notLogin();
        }
    }

    public function editDeal($slug,Request $request){
        if (Auth::check()) {
            $validator = Validator::make($request->all(), [
                'title' => 'required|string|max:255',
                'start_date' => 'required',
                'description' => 'required',
                'employee' => 'required',
                'services' => 'required',
                'discount_type' => 'required|string',
                'discount' => 'required|integer',
                'expire_date' => 'required',
                'business_id' => 'required',
                'banner' => 'required',
            ]);
            if ($validator->fails()) {
                $j_errors = $validator->errors();
                $errors = (array) json_decode($j_errors);
                $key = array_key_first($errors);
                return $this->error($errors[$key][0],"",422);
            }

            $input = $request->all();
            // $user_id = Auth::id();
            $business_id = $input['business_id'];
            if(!empty($business_id)){
                
                $total_price = 0;
                $total_duration = 0;
                $discounted_price = 0;

                for ($i=0; $i < count($input['services']); $i++) { 
                    $total_price = $total_price + $input['services'][$i]['cost'];
                    $total_duration = $total_duration + $input['services'][$i]['duration'];
                }

                if ($input['discount_type'] == 'percent') {
                    $discount = ($total_price * $input['discount']) / 100;
                    $discounted_price = $total_price - $discount;
                }else{
                    $discounted_price = $total_price - $input['discount'];
                }

                if ($input['package'] === true) {
                    $package = 1;
                }else{
                    $package = 0;
                }

                if (preg_match('/^data:image\/(\w+);base64,/', $input['banner'])) {
                    $banner = $this->AWS_FileUpload('base64', $input['banner'],'deals');
                }else{
                    $banner = $input['banner'];
                }

                // Add new Deal
                $resp = Deal::where('business_id',$business_id)
                ->where('slug',$slug)->update([
                    'user_id' => $input['employee']['user']['id'],
                    'employee_id' => $input['employee']['id'],
                    "title" => $input['title'],
                    'description' => $input['description'],
                    'banner' => $banner,
                    'startDate' => $input['start_date'],
                    'endDate' => $input['expire_date'],
                    'unit' => $input['discount_type'],
                    'discount_value' => $input['discount'],
                    'total_price' => $total_price,
                    'discounted_price' => $discounted_price,
                    'total_duration' => $total_duration,
                    'is_package' => $package,
                    'status' => $input['status'],
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
                if(!$resp){
                    return $this->error("Something went wrong while adding a deal");
                }

                $deal = Deal::where('business_id',$business_id)->where('slug',$slug)->first();
                Deal_service::where('deal_id',$deal->id)->delete();
                // Add Deal Services
                for ($i=0; $i < count($input['services']); $i++) { 
                    if ($input['services'][$i]['selected'] == true) {
                        $deal_service = Deal_service::where('service_id',$input['services'][$i]['service_id'])->create([
                            'deal_id' => $deal->id,
                            'service_id' => $input['services'][$i]['service_id'],
                            "business_service_id" => $input['services'][$i]['business_service_id'],
                            'status' => 1
                        ]);
                    }
                }
                if(!$deal_service){
                    return $this->error("Something went wrong while editing deal services");
                }else{
                    return $this->success();
                }               
            }else{
                return $this->error("You don't have any business registered at the moment");
            }
        }else{
            return $this->notLogin();
        }
    }


    public function relatedDeals($slug){
        $deals = [];
        $city='';
        $state = '';
        $business = Deal::select('business_id')->with('business:id,city,state')->where('slug', $slug)->first();        
        
        if (!empty($business->business->city)) {
            $city = $business->business->city;
            $state = $business->business->state;
            
            $businesses = Business::select(DB::raw('group_concat(id) as ids'))->where('city',$city)->where('state',$state)->where('status',1)->value('ids');
            $business_ids = explode(",",$businesses);

            if (!empty($businesses)) {
                $deals = Deal::with("user")->with("business")->whereIn('id',$business_ids)->where('slug','!=',$slug)->whereDate('endDate','>=', Carbon::now())->where('status',1)->get();
            }
        }
        return $this->success(['deals'=>$deals,'city'=>$city,'state'=>$state]);
    }

    public function getBookingDeal(Request $request,$slug){
        $business = Helper::getBusinessBySlug($slug,1);
        $data = ['services'=>[],'renderLocations'=>[],'active_loc'=>'','active_services'=>[]];
        if (!empty($business) && !empty($request->deal)) {
            $business_id = $business->id;

            // Get assigned employee render locations 
            $rl = Employee::select(DB::raw('group_concat(DISTINCT(service_rendered)) as serv_render_loc'))->where('business_id',$business_id)->where('status',1)->first();
            if (!empty($rl->serv_render_loc)) {
                $data['renderLocations'] = explode(",",$rl->serv_render_loc);
                $data['active_loc'] = $data['renderLocations'][0];
            }

            $deal = Deal::select('id',"title",'slug','user_id','business_id','employee_id')->with('deal_services.service:id,title')->with('deal_services.business_service:id,cost,duration,tax')->where('slug',$request->deal)->first();
            if (!empty($deal)) {
                $data['services'] = [
                    ['label'=>'Deal','options'=>[['label'=>$deal->title,'value'=>$deal,'disabled'=>false]]]
                ];
                $data['active_services'] = [['label'=>$deal->title,'value'=>$deal,'disabled'=>false]];
            }
        }
        return $this->success($data);
    }

    public function updateDealStatus(Request $request)
	{
		$validator = Validator::make($request->all(), [
			'slug' => 'required',
			'status' => 'required'
		]);
		if ($validator->fails()) {
			$j_errors = $validator->errors();
			$errors = (array) json_decode($j_errors);
			$key = array_key_first($errors);
			return $this->error($errors[$key][0],"",422);
		}
        $user_id = Auth::id();
    	$business = Helper::getBusinessByUserId($user_id);

		$resp = $this->bookingInfo = Deal::where('business_id',$business->id)->where('slug',$request->slug)->update(['status'=>$request->status,'updated_at'=>date('Y-m-d H:i:s')]);
        if($resp){
            return $this->success("","Deal update successfully");
        }
		return $this->error("Something went wrong.");
	}
}