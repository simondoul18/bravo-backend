<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Carbon;
use App\Traits\ApiResponser;
use App\Helpers\Helper;
use App\Models\Follow;
use App\Traits\Aws;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Storage;

class FollowController extends Controller
{
    use ApiResponser;
    use Aws;

    public function businessFollows(Request $request){
        $follows = Follow::where('business_id', $request->id)->get();
        return $this->success($follows);
    }

    public function userFollows(Request $request){
        $follows = Follow::where('user_id', $request->id)->get();
        return $this->success($follows);
    }

    public function uploadGalleryImages(Request $request){
        if (Auth::check()) {
            $validator = Validator::make($request->all(), [
                'id' => 'required',
                'images' => 'required'
            ]);
            if ($validator->fails()) {
                $j_errors = $validator->errors();
                $errors = (array) json_decode($j_errors);
                $key = array_key_first($errors);
                return $this->error($errors[$key][0],"",422);
            }

            if($request->hasfile('images'))
            {
                foreach($request->file('images') as $key => $file)
                {
                    // return $file;

                    $banner = $this->AWS_FileUpload('simple', $file, 'gallery');

                    $gallery = Gallery::create([
                        'business_id' => $request->id,
                        "title" => $request->title[$key],
                        'image' => $banner
                    ]);
                }
            }
            if(!$gallery){
                return $this->error("Something went wrong while uploading images");
            }else{
                return $this->success();
            }
        }else{
            return $this->notLogin();
        }
    }

    public function deleteGalleryImage(Request $request){
        $images = Gallery::where('id', $request->id)->delete();
        if ($images) {
            return $this->success('','Image deleted successfully!');
        }else{
            return $this->error('Something went wrong while deleting image');
        }
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
                    $output[] = '<img class="img-fluid banner-img" alt="" src="'.$deal->banner.'" >';
                    // $output[] = '<img class="img-fluid banner-img" alt="" src="https://s3.us-east-2.amazonaws.com/images.ondaq.com/deals/thumbnail/default.jpg" >';
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
                        $output[] = '<a class="solds" href="#">Active</a>';
                    }else{
                        $output[] = '<a class="losts" href="#">Inactive</a>';
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
                'banner' => 'required',
            ]);
            if ($validator->fails()) {
                $j_errors = $validator->errors();
                $errors = (array) json_decode($j_errors);
                $key = array_key_first($errors);
                return $this->error($errors[$key][0],"",422);
            }

            $user_id = Auth::id();
            $businessInfo = Helper::getBusinessByUserId($user_id);
            if(!empty($businessInfo)){
                $input = $request->all();
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

                $banner = $this->AWS_FileUpload('base64', $input['banner']);

                // Add new Deal
                $deal = Deal::create([
                    'business_id' => $businessInfo->id,
                    'user_id' => $input['employee']['user']['id'],
                    'employee_id' => $input['employee']['id'],
                    "title" => $input['title'],
                    //'slug' => Str::slug($input['title']),
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

    public function editDeal($slug,Request $request)
    {
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
                'banner' => 'required',
            ]);
            if ($validator->fails()) {
                $j_errors = $validator->errors();
                $errors = (array) json_decode($j_errors);
                $key = array_key_first($errors);
                return $this->error($errors[$key][0],"",422);
            }

            $user_id = Auth::id();
            $businessInfo = Helper::getBusinessByUserId($user_id);
            if(!empty($businessInfo)){
                $input = $request->all();
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
                    $banner = $this->AWS_FileUpload('base64', $input['banner']);
                }else{
                    $banner = $input['banner'];
                }

                // Add new Deal
                $resp = Deal::where('business_id',$businessInfo->id)
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

                $deal = Deal::where('business_id',$businessInfo->id)->where('slug',$slug)->first();
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
        $business_id = Deal::where('slug', $slug)->value('business_id');
        $deals = [];
        if (!empty($business_id)) {
            $deals = Deal::with("user")->with("business")->where('business_id', $business_id)->where('slug','!=',$slug)->whereDate('endDate','>=', Carbon::now())->where('status',1)->get();
        }
        return $this->success($deals);
    }
}