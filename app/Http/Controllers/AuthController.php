<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Employee;
use App\Models\Business_service;
use App\Models\Business;
use App\Models\Business_hour;
use App\Models\Stripe_connect_account;
use App\Traits\ApiResponser;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use App\Helpers\Helper;

use App\Notifications\Welcome;
use App\Notifications\WelcomeMail;

class AuthController extends Controller
{
    use ApiResponser;

    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|string|email|unique:users,email',
            'phone' => 'required|max:20',
            'gender' => 'required|string|max:10',
            'password' => 'required|string|min:8',
            'c_password' => 'required|same:password',
            'dialCode' => 'required',
        ]);
        if ($validator->fails()) {
            $j_errors = $validator->errors();
            $errors = (array) json_decode($j_errors);
            $key = array_key_first($errors);

            return $this->error($errors[$key][0],"",422);

            //return response()->json($errors[$key][0], 422);
            //return response()->json(['error'=>current( (Array)$errors )], 422);



            // if (!empty($errors->email[0])) {
            //     return response()->json(['validate'=>"The email has already been taken."], 200);
            // }else{
            //     return response()->json(['error'=>$validator->errors()], 401);
            // }
        }
        $input = $request->all();

        $user = User::create([
            "name" => $input['first_name']." ".$input['last_name'],
            'first_name' => $input['first_name'],
            'last_name' => $input['last_name'],
            'password' => bcrypt($input['password']),
            'phone' => '+'.$input['dialCode'].preg_replace('/\D+/', '', $input['phone']),
            'gender' => ucfirst($input['gender']),
            'email' => $input['email'],
            'updated_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s')
        ]);
        $user['is_owner'] = 0;
        $user->notify(new Welcome($user));
        $user->notify(new WelcomeMail($user));
        return $this->success([
            'token' => $user->createToken('API Token')->plainTextToken,
            'user' => $user,
            'business' => [
                'has_business'=>0,
                'id'=>'',
                'list' => [],
                'active_business'=>[],
                'profile_completetion'=>0,
            ]
        ]);
    }

    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required',
            'password' => 'required',
        ]);
        if ($validator->fails()) {
            $j_errors = $validator->errors();
            $errors = (array) json_decode($j_errors);
            $key = array_key_first($errors);
            return $this->error($errors[$key][0],"",422);
        }
        $input = $request->all();
        if (Auth::attempt($input)) {
            $user = Auth::user();
            if ($user->user_status == 0) {
                return $this->error('Your account is deactivated. Please contact admin for details.');
            }else{
                $data = [
                    'has_business'=>0,
                    'id'=>'',
                    'list' => [],
                    'active_business'=>[]
                ];

                // Get Business
                $business = Helper::getBusinessByUserId($user->id);
                if (!empty($business)) {
                    $data['id'] = $business->id;
                    $data['active_business'] = [
                        'id'=>$business->id,
                        'slug'=>$business->title_slug,
                        'title'=>$business->title,
                        'picture'=>$business->profile_pic,
                        'is_owner'=>1,
                        'profile_completetion'=> $business->profile_completetion,


                    ];
                }

                // Get List of Assigned Businesses
                $businesses = Helper::getUserAssignedBusiness($user->id);
                if (!empty($businesses) && count($businesses) > 0) {
                    $data['list'] = $businesses;
                    $data['has_business'] = 1;
                    if (empty($data['active_business'])) {
                        $data['active_business'] = $businesses[0];
                    }
                }

                return $this->success([
                    'user'=>$user,
                    'business'=>$data,
                    'token' => auth()->user()->createToken('API Token')->plainTextToken
                ]);
            }
        }else{
            return $this->error('Invalid Email or Password');
        }
    }

    public function logout()
    {
        auth()->user()->tokens()->delete();
        return $this->success([],'Successfully logout');
    }

    public function getProfileStatus($business_id)
    {
        //$id = Auth::id();
        $business = Helper::getBusinessById($business_id);
        if(!empty($business->description) && !empty($business->facilities) &&  !empty($business->status)){
            $Schedule = Business_hour::where('business_id',$business_id)->where('isOpen',1)->count();
            if($Schedule == 0){
                return $this->success(['step'=> 2, 'status'=> 'yes','update_storage'=> 'no']);
            }

            $services = Business_service::where('business_id',$business_id)->count();
            if($services == 0){
                return $this->success(['step'=> 3, 'status'=> 'yes','update_storage'=> 'no']);
            }

            $AddEmployee = Employee::where('business_id',$business_id)->where('status',1)->count();
            if($AddEmployee == 0){
                return $this->success(['step'=> 4, 'status'=> 'yes','update_storage'=> 'no']);
            }

            $checkStripe = Stripe_connect_account::where('business_id',$business_id)->count();
            if($checkStripe == 0){
                return $this->success(['step'=> 5, 'status'=> 'yes','update_storage'=> 'no']);
            }

            $update_status = Business::where('id',$business_id)->update(['profile_completetion'=>1]);
            if ($update_status) {
                return $this->success(['update_storage'=> 'yes', 'status'=> 'no']);
            }
        }else{
            return $this->success(['step'=> 1, 'status'=> 'yes','update_storage'=> 'no']);
        }
        return $this->success(['step'=> 0, 'status'=> 'no','update_storage'=> 'no']);
    }

    // public function assignedBusinesses($returnType='',$user_id=''){
    //     if (Auth::check()) {
    //         $user_id = Auth::id();
    //         $business_list = Helper::getUserAssignedBusiness($user_id);
    //     }else{
    //         return $this->error("Access Denied. Please login first.");
    //     }





    //     if (empty($user_id)) {
    //         if (Auth::check()) {
    //             $user_id = Auth::id();
    //         }else{
    //             return $this->error("Access Denied. Please login first.");
    //         }
    //     }
    //     $data = [];
    //     $business = Helper::getBusinessByUserId($user_id);
    //     // Get Employees
    //     $q = Employee::select("business_id")->with("business:id,title,title_slug,profile_pic")->where('user_id',$user_id);
    //     if (!empty($business)) {
    //         $data[] = [
    //             'id'=>$business->id,
    //             'slug'=>$business->title_slug,
    //             'title'=>$business->title,
    //             'picture'=>$business->profile_pic,
    //             'is_owner'=>1
    //         ];
    //         $q->where('business_id',"!=",$business->id);
    //     }
    //     $employees = $q->distinct('business_id')->get();
    //     if (!empty($employees) && count($employees) > 0) {
    //         foreach ($employees as $key => $emp) {
    //             $data[] = [
    //                 'id'=>$emp->business_id,
    //                 'slug'=>$emp->business->title_slug,
    //                 'title'=>$emp->business->title,
    //                 'picture'=>$emp->business->profile_pic,
    //                 'is_owner'=>0];
    //         }
    //     }
    //     if ($returnType == 'array') {
    //         return $data;
    //     }else{
    //         return $this->success($data);
    //     }
	// }
}