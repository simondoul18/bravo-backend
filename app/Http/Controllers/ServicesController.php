<?php
namespace App\Http\Controllers;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use App\Models\Category;
// use App\Models\Business;
// use App\Models\User;
use App\Models\Service;
use App\Models\Type;
use App\Models\Business_category;
use App\Models\Business_service;
use App\Models\Business_service_employee;
use App\Models\Employee;
use App\Traits\ApiResponser;
use App\Helpers\Helper;
use DateTime;
use DateTimeZone;

use Illuminate\Database\Eloquent\Builder;

class ServicesController extends Controller
{
    use ApiResponser;

    // Business Types
    public function getBusinessTypes(Request $request){
        $q = Type::where('status',1);
        if ($request->has('for') && $request->query('for') === 'homePage') {
            $q->select("id","title","professional_title","icon");
            $q->where("onFront",1);
        }
        $types = $q->get();
        return $this->success($types);
    }




    public function addService(Request $request){
        if (Auth::check()) {
            $user_id = Auth::id();
            $validator = Validator::make($request->all(), [
                'category' => 'required',
                'price' => 'required|min:1',
                'description' => 'required',
            ]);
            if ($validator->fails()) {
                $j_errors = $validator->errors();
                $errors = (array) json_decode($j_errors);
                $key = array_key_first($errors);
                return $this->error($errors[$key][0],"",422);
            }
            $param = $request->all();
            $businessId = Helper::getBusinessByUserId($user_id);
            if(!empty($businessId)){
                $servicesArr = [
                    'category_id' => $param['category']['id'],
                    'business_category_id' => $param['category']['business_cate'],
                    'commission' => $param['commission'],
                    'description' => $param['description'],
                    'business_id' =>  $businessId->id,
                    'status' => $param['status'],
                    'only_for_booking' => $param['onlyForBooking'],
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ];

                // Service
                if (!empty($param['service']) && !empty($param['service']['id'])) {
                    $servicesArr['service_id'] = $param['service']['id'];
                    //$servicesArr['business_category_id'] = $param['service']['business_category_id'];
                }elseif (!empty($param['service_title'])) {
                    $service_id = Service::create([
                        'busines_id' => $businessId->id,
                        'category_id' => $param['category']['id'],
                        'title' => $param['service_title'],
                        'description' => $param['description']
                    ])->id;
                    if (!empty($service_id)) {
                        $servicesArr['service_id'] = $service_id;
                        $servicesArr['default_service'] = 0;
                    }else{
                        return $this->error("Something wrong. Service did not added.","",422);
                    }
                }else{
                    return $this->error("Please choose any service.","",422);
                }

                // Price
                $price = 0;
                if (!empty($param['discounted_price']) && $param['discounted_price'] > 0) {
                    if ($param['discounted_price'] < $param['price']) {
                        $price = $param['discounted_price'];
                        $servicesArr['org_cost'] = $param['price'];
                    }else{
                        return $this->error("Discounted price must be less than Original price.","",422);
                    }
                }else{
                    $price = $param['price'];
                }
                $servicesArr['cost'] = $price;

                // Requested Price
                if ($param['request_price'] === true) {
                    $servicesArr['is_requested'] = 1;
                }else{
                    $servicesArr['is_requested'] = 0;
                }


                // Duration
                if($param['flex']['status'] === true){
                    if (!empty($param['flex']['initial']) && !empty($param['flex']['delay']) && !empty($param['flex']['finish'])) {
                        $servicesArr['duration'] = $param['flex']['initial'] + $param['flex']['delay'] + $param['flex']['finish'];
                        $servicesArr['initial_duration'] = $param['flex']['initial'];
                        $servicesArr['gap'] = $param['flex']['delay'];
                        $servicesArr['finish_duration'] = $param['flex']['finish'];
                    }else{
                        return $this->error("Flex values are not correct.","",422);
                    }
                }else{
                    if (!empty($param['duration'])){
                        $servicesArr['duration'] = $param['duration'];
                    }else{
                        return $this->error("Please enter duration.","",422);
                    }
                }


                //Tax
                if(!empty($param['tax']['value']) && $param['tax']['status'] === true){
                    //$servicesArr['tax'] = ($price / 100) * $param['tax']['value'];
                    $servicesArr['tax'] = $param['tax']['value'];
                }else{
                    $servicesArr['tax'] = null;
                }

                //return $this->success($servicesArr);
                if (!empty($param['id'])) {
                    Business_service::where('id',$param['id'])->update($servicesArr);
                    $businessServiceId =  $param['id'];
                }else{
                    $businessServiceId = Business_service::create($servicesArr)->id;
                }

                // Add Professionals
                if($businessServiceId){
                    if(!empty($param['professionals'])){
                        foreach ($param['professionals'] as $key => $employee) {
                            Business_service_employee::create([
                                'business_id' => $businessId->id,
                                'employee_id' => $employee['id'],
                                'user_id' => $employee['user_id'],
                                'service_id' => $servicesArr['service_id'],
                                'business_service_id' => $businessServiceId,
                                'updated_at' => date('Y-m-d H:i:s'),
                                'created_at' => date('Y-m-d H:i:s')
                            ]);
                        }
                    }
                    return $this->success($businessServiceId);
                }else{
                    return $this->error("Something wrong. Service does not created.");
                }
            }else{
                return $this->error("No business found.");
            }
        }else{
            return $this->notLogin();
        }
    }
    public function getServices($cate_id=""){
        if (!empty($cate_id)) {
            $services = Service::with('category')->where('category_id',$cate_id)->where('status',1)->get();
        }else{
            $services = Service::with('category:id,title')->where('status',1)->get();
        }
        return $this->success($services);
    }
    public function getServiceDetailForSearchSuggestion($name)
    {
        $keyword = str_replace('+',' ',$name);
        $service = Service::with('category')->where('title','like',$keyword)->first();
        $data = [];
        if (!empty($service)) {
            $data = ['id'=>$service->id,'name'=>$service->title,'icon'=>'','type'=>'service'];
        }
        return $this->success($data);
    }
    public function getBusinessServices($serviceId=""){
        if (Auth::check()) {
            $user_id = Auth::id();
            $businessInfo = Helper::getBusinessByUserId($user_id);

            if(!empty($businessInfo)){
                if (!empty($serviceId)) {
                    $services = Business_service::with('professionals')->where('id',$serviceId)->where('business_id',$businessInfo->id)->where('status','1')->first();
                }else{
                    $services = Business_service::with('service')->where('business_id',$businessInfo->id)->where('status','1')->get();
                }
                return $this->success($services);
            }else{
                return $this->error("No business found.");
            }
        }else{
            return $this->notLogin();
        }
    }
    public function deleteBusinessService($id){
        if (Auth::check()) {
            $user_id = Auth::id();
            $businessInfo = Helper::getBusinessByUserId($user_id);

            if(!empty($businessInfo)){
                $resp = Business_service::where('business_id',$businessInfo->id)->where('id',$id)->delete();
                if ($resp) {
                    Business_service_employee::where('business_id',$businessInfo->id)->where('business_service_id',$id)->delete();
                    return $this->success("","Sucessfully deleted.");
                }else{
                    return $this->error("Sorry! Service did not deleted.");
                }

            }else{
                return $this->error("No business found.");
            }
        }else{
            return $this->notLogin();
        }
    }

    public function getCategoires($action=''){
        if ($action == 'only') {
            return $this->success(Category::where('status',1)->get());
        }else{
            return $this->success(Category::with('services')->where('status',1)->get());
        }
    }
    public function getBusinessCategoires($id=''){
        if (empty($id)){
            if (!empty($_GET['slug'])) {
                $businessInfo = Helper::getBusinessBySlug($_GET['slug']);
                $id = $businessInfo->id;
            }elseif (Auth::check()) {
                $businessInfo = Helper::getBusinessByUserId(Auth::id());
                $id = $businessInfo->id;
            }else{
                return $this->notLogin();
            }
        }

        if (!empty($_GET['action']) && $_GET['action'] == 'with_business_services') {
            return $this->success(Category::whereHas('business_category', function (Builder $query) use ($id) {
                $query->where('business_id', '=', $id);
            })->with(['business_service' => function ($query) use ($id){
                $query->select(["business_services.*","services.title"])
                ->leftJoin('services', 'services.id', '=', 'service_id')->where('business_id', '=', $id);
            }])->get());
            //return $this->success(Business_category::with('category:id,title')->with('business_services')->where('business_id',$id)->where('status',1)->get());
        }elseif (!empty($_GET['action']) && $_GET['action'] == 'with_services') {
            return $this->success(Category::whereHas('business_category', function (Builder $query) use ($id) {
                $query->where('business_id', '=', $id);
            })->with(['business_category' => function ($query) use ($id){
                $query->where('business_id', '=', $id);
            }])->with('services')->get());
        }else{
            return $this->success(Business_category::with('category:id,title')->where('business_id',$id)->where('status',1)->get());
        }
    }
    public function getBookingServices(Request $request,$slug){
        $business = Helper::getBusinessBySlug($slug,1);
        $data = ['services'=>[],'renderLocations'=>[],'active_loc'=>'','active_services'=>[],"timeZone" => ""];

        if (!empty($business)) {
            $business_id = $business->id;

            // Get assigned employee render locations
            $rl = Employee::select(DB::raw('group_concat(DISTINCT(service_rendered)) as serv_render_loc'))->where('business_id',$business_id)->where('status',1)->orderBy('service_rendered','ASC')->first();
            if (!empty($rl->serv_render_loc)) {
                $allAcceptedLocations = explode(",",$rl->serv_render_loc);
                if(in_array(3,$allAcceptedLocations)){
                    $data['renderLocations'] = [1,2];
                }else{
                    $data['renderLocations'] = $allAcceptedLocations;
                }

                $data['active_loc'] = $data['renderLocations'][0];
            }

            $categories = Business_category::with("category:id,title")
            ->with("business_services.service:id,title")
            ->with("business_services.professionals.employee:id,user_id,service_rendered")
            ->where('status',1)->where('business_id',$business_id)->get();

            if (!empty($categories) && count($categories) > 0) {
                foreach ($categories as $key => $cate) {
                    if (!empty($cate->business_services) && count($cate->business_services) > 0) {
                        $label = $cate->category->title;
                        $options = [];
                        foreach ($cate->business_services as  $service) {
                            // Check service is Assigned to any Employees
                            if (!empty($service->professionals) && count($service->professionals) > 0) {
                                // Get Assigned Employees Base on Render location
                                $users = [];

                                foreach ($service->professionals as $emp) {
                                    // Check Service render location

                                    if (isset($emp) && is_object($emp->employee) && isset($emp->employee->service_rendered) && $emp->employee->service_rendered == $data['active_loc']) {
                                        if (isset($emp->employee->user_id)) {
                                            $users[] = $emp->employee->user_id;
                                        }
                                    }



                                    // if ($emp->employee->service_rendered == $data['active_loc']) {
                                    //     $users[] = $emp->employee->user_id;
                                    // }



                                    //$users[] = ['id'=>$emp->employee->user_id,'render_loc'=>$emp->employee->service_rendered];
                                }
                                if (!empty($users) && count($users) > 0) {
                                    $serviceData = [
                                        'id'=> $service->service->id,
                                        'business_service_id'=> $service->id,
                                        'title'=> $service->service->title,
                                        'cost'=> $service->cost,
                                        'duration'=> $service->duration,
                                        'tax' => $service->tax,
                                        'assigned_users' => $users
                                    ];

                                    $options[] = ['label'=>$service->service->title,'value'=>$serviceData,'disabled'=>false];
                                    if (!empty($request->service)) {
                                        if ($service->id == $request->service) {
                                            $data['active_services'][] = $serviceData;
                                        }
                                    }
                                }else{
                                    // No service in this render Location
                                }
                            }
                        }
                        if (!empty($options) && count($options) > 0) {
                            $data['services'][] = ['label'=>$label,'options'=>$options];
                        }
                    }
                }
            }
            
            $timeZones = [
				"America/New_York" => "E",
				"America/Chicago" => "C",
				"America/Denver" => "M",
				"America/Phoenix" => "M",
				"America/Los_Angeles" => "P",
				"America/Anchorage" => "AK",
				"Pacific/Honolulu" => "H",
			];
            foreach ($timeZones as $timeZone => &$displayName) {
				$dateTimeZone = new DateTimeZone($timeZone);
				$now = new DateTime("now", $dateTimeZone);
				
				if ($now->format('I') == '1') {
					// If currently observing DST, update the display name
					$displayName .= "DT";
				} else {
					// If not observing DST, update the display name
					$displayName .= "ST";
				}
			}

            $data["timeZone"] = $timeZones[$business->timeZone];
            // return $this->success(['services'=>$data,'renserLocations'=>$render_loc,'active_loc'=>$location]);

        }
        return $this->success($data);
    }
    public function getBookingServicesOld($slug,$location,$is_requested=''){
        $business = Helper::getBusinessBySlug($slug,1);

        if (!empty($business)) {
            $business_id = $business->id;

            // Get assigned employee render locations
            $rl = Employee::select(DB::raw('group_concat(DISTINCT(service_rendered)) as serv_render_loc'))->where('business_id',$business_id)->where('status',1)->first();
            $render_loc = [];
            if (!empty($rl->serv_render_loc)) {
                $render_loc = explode(",",$rl->serv_render_loc);
                if ($location == 0) {
                    if (in_array(1, $render_loc)){
                        $location = 1;
                    }elseif (in_array(2, $render_loc)){
                        $location = 2;
                    }else{
                        return $this->success([]);
                    }
                }
            }else{
                return $this->success([]);
            }
            $categories = Business_category::with("category:id,title")
            ->with("business_services.service:id,title")
            ->with("business_services.professionals.employee:id,user_id,service_rendered")
            ->where('status',1)->where('business_id',$business_id)->get();

            $data = [];
            if (!empty($categories)) {
                foreach ($categories as $key => $cate) {
                    if (!empty($cate->business_services) && count($cate->business_services) > 0) {
                        $label = $cate->category->title;
                        $options = [];
                        foreach ($cate->business_services as  $service) {
                            // Check service is Assigned to any Employees
                            if (!empty($service->professionals) && count($service->professionals) > 0) {
                                // Get Assigned Employees Base on Render location
                                $users = [];
                                $ren_at_business = 0;
                                $ren_at_client = 0;
                                foreach ($service->professionals as $emp) {
                                    // Check Service render location
                                    if (isset($emp) && is_object($emp->employee) && $emp->employee->service_rendered == $location) {
                                        $users[] = $emp->employee->user_id;
                                    }

                                    // if ($emp->employee->service_rendered == $location) {
                                    //     $users[] = $emp->employee->user_id;
                                    // }

                                    //$users[] = ['id'=>$emp->employee->user_id,'render_loc'=>$emp->employee->service_rendered];
                                }
                                if (!empty($users) && count($users) > 0) {
                                    $serviceData = [
                                        'id'=> $service->service->id,
                                        'business_service_id'=> $service->id,
                                        'title'=> $service->service->title,
                                        'cost'=> $service->cost,
                                        'duration'=> $service->duration,
                                        'tax' => $service->tax,
                                        'assigned_users' => $users
                                    ];
                                    $options[] = ['label'=>$service->service->title,'value'=>$serviceData,'disabled'=>false];
                                }else{
                                    // No service in this render Location
                                }
                            }
                        }
                        if (!empty($options) && count($options) > 0) {
                            $data[] = ['label'=>$label,'options'=>$options];
                        }
                    }
                }
            }
            return $this->success(['services'=>$data,'renserLocations'=>$render_loc,'active_loc'=>$location]);


            // $services = Category::with("services.employeeServices")
            // ->with('services.business_service', function ($query) use ($id) {
            //     $query->where('business_id', $id);
            // })
            // ->with('services', function ($query) use ($id) {
            //     $query->where('services.status',1)->whereHas('employeeServices', function (Builder $query) use ($id){
            //         $query->where('business_id', $id);
            //     });
            // })->where('status',1)->get();
            //return response()->json($services);

            // $data = [];
            // if (!empty($services)) {
            //     foreach ($services as $key => $cate) {
            //         if (!empty($cate->services)) {
            //             $label = $cate->title;
            //             $options = [];
            //             foreach ($cate->services as  $service) {
            //                 $dis = false;
            //                 // if (!empty($employee_id)) {
            //                 //     foreach ($service->employeeServices as $emp) {
            //                 //         if ($emp->employee_id != $employee_id) {
            //                 //             $dis = true;
            //                 //         }
            //                 //     }
            //                 // }
            //                 $serviceData = [
            //                     'id'=> $service->id,
            //                     'title'=> $service->title,
            //                     'cost'=> $service->business_service->cost,
            //                     'duration'=> $service->business_service->duration,
            //                 ];
            //                 $options[] = ['label'=>$service->title,'value'=>$serviceData,'disabled'=>$dis];
            //             }
            //             if (!empty($options)) {
            //                 $data[] = ['label'=>$label,'options'=>$options];
            //             }
            //         }
            //     }
            // }
            // return $this->success($data);

        }else{
            return response()->json([]);
        }
    }

    public function getEmployeeServices(Request $request){
        $param = $request->all();
        $validator = Validator::make($request->all(), [
            'id' => 'required',
            //'business_id' => 'required',
        ]);
        if ($validator->fails()) {
            $j_errors = $validator->errors();
            $errors = (array) json_decode($j_errors);
            $key = array_key_first($errors);
            return $this->error($errors[$key][0],"",422);
        }

        $user_id = $param['id'];
        $business = '';
        if (empty($param['business_id'])) {
            if (Auth::check()) {
                $business = Helper::getBusinessByUserId(Auth::id());
            }
        }else{
            if (is_numeric($param['business_id'])) {
                $business = Helper::getBusinessById($param['business_id']);
            }else{
                $business = Helper::getBusinessBySlug($param['business_id']);
            }
        }

        if (!empty($business)) {
            $servicesData = Business_service_employee::with("business_service")->with("service")->where('business_id',$business->id)->where('user_id',$user_id)->get();

            if (!empty($param['platform'])) {
                $data = [];
                if (!empty($servicesData) && count($servicesData) > 0) {
                    foreach ($servicesData as $key => $service) {
                        $data[] = [
                            'id'=> $service->service_id,
                            'business_service_id'=> $service->business_service_id,
                            'title'=> $service->service->title,
                            'cost'=> $service->business_service->cost,
                            'duration'=> $service->business_service->duration,
                            'tax' => $service->business_service->tax,
                            'selected' => false
                        ];
                    }
                }
            }else{
                $data = $servicesData;
            }
            return $this->success($data);
        }else{
            return $this->error("No data Found");
        }
    }

    public function getServicesForQueue(Request $request){
        $validator = Validator::make($request->all(), [
            'user_id' => 'required',
            'business' => 'required'
        ]);
        if ($validator->fails()) {
            $j_errors = $validator->errors();
            $errors = (array) json_decode($j_errors);
            $key = array_key_first($errors);
            return $this->error($errors[$key][0],"",422);
        }
        $param = $request->all();

        if (is_numeric($param['business'])) {
            $business = Helper::getBusinessById($param['business']);
        }else{
            $business = Helper::getBusinessBySlug($param['business']);
        }
        if (!empty($business)) {
            $services = Business_service_employee::QueueServices()
            ->with("service")
            ->where('business_service_employees.business_id',$business->id)
            ->where('business_service_employees.user_id',$param['user_id'])
            ->where('business_services.only_for_booking',0)->get();
            return $this->success($services);
        }else{
            return $this->success([]);
        }
    }
    public function getRequestedServices($slug){
        if (is_numeric($slug)) {
            $id = $slug;
            //$business = Helper::getBusinessById($slug);
        }else{
            $business = Helper::getBusinessBySlug($slug);
            $id = $business->id;
        }
        if (!empty($id)) {

            $services =Business_service::with("service:id,title")->has('professionals')
            ->where('business_services.business_id',$id)
            ->where('business_services.is_requested',1)
            ->where('business_services.status',1)->get();

            // $services = Business_service_employee::QueueServices()
            // ->with("service")
            // ->where('business_service_employees.business_id',$id)
            // ->where('business_services.is_requested',1)
            // ->where('business_services.status',1)->get();
            return $this->success($services);
        }else{
            return $this->success([]);
        }
    }

}