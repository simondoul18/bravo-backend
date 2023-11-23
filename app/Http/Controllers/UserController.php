<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use App\Traits\ApiResponser;
use App\Models\User;
use App\Models\Employee;
use Illuminate\Validation\ValidationException;
use App\Traits\Aws;

class UserController extends Controller
{
    use ApiResponser, Aws;

    public function getUserDetail($action='',Request $request){
        $user='';
        if (($action == 'byEmail' || $action == 'forEmployee') && !empty($request->email)) {
            $user = User::where('email',$request->email)->first();
            // if ($action == 'forEmployee') {
            //     $employee = Employee::where('user_id',$user->id)->first();
            //     if (!empty($employee)) {
            //         return $this->error("This user already engaged with another.","already_exist");
            //     }
            // }
        }
        //elseif ($action == 'forEmployee' && !empty($request->email)) {
        //     $user = User::where('email',$request->email)->first();
        // }

        if (!empty($user)) {
            return $this->success($user);
        }else{
            return $this->error("No User Found.");
        }
    }

    public function getCustomerByID(Request $request)
    {
        $user = User::where('id',$request->id)->first();

        if (!empty($user)) {
            return $this->success($user);
        }else{
            return $this->error("No User Found.");
        }
    }

    public function SearchUserByPhoneOrEmail(Request $request)
    {
        $user = User::where('email',$request->emailphone)->orWhere('phone',$request->emailphone)->first();

        if (!empty($user)) {
            return $this->success($user);
        }else{
            return $this->success("No User Found.");
        }
    }

    public function updateDetail(Request $request)
    {
        if (Auth::check()) {
            $valiate_arr=[
                'first_name' => 'required|string|max:255',
                'last_name' => 'required|string|max:255',
                // 'email' => 'required|string|email|unique:users,email',
                'phone' => 'required|max:20',
                'dialCode' => 'required',
                'gender' => 'required|string|max:10',
                'birth_date' => 'required',
                'address' => 'required',
                'apt_suite' => 'required|integer',
                'bio' => 'required|max:255'
            ];
            if (!empty($request->input('password'))){
                $valiate_arr['curr_password'] = 'required';
                $valiate_arr['password'] = ['required', Password::min(8)];
                $valiate_arr['confirmPass'] = 'required|same:password';
            }
            $validator = Validator::make($request->all(),$valiate_arr);
            if ($validator->fails()) {
                $j_errors = $validator->errors();
                $errors = (array) json_decode($j_errors);
                $key = array_key_first($errors);
                return $this->error($errors[$key][0],"",422);
            }

            $user_id = Auth::id();
            $input = $request->all();

            $user = [
                "name" => $input['first_name']." ".$input['last_name'],
                'first_name' => $input['first_name'],
                'last_name' => $input['last_name'],
                // 'password' => bcrypt($input['password']),
                'phone' => '+'.$input['dialCode'].preg_replace('/\D+/', '', $input['phone']),
                'gender' => ucfirst($input['gender']),
                // 'email' => $input['email'],
                'birth_date' => $input['birth_date'],
                'address' => $input['address'],
                'apt_suite' => $input['apt_suite'],
                'bio' => $input['bio'],
                'updated_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s')
            ];
            if (!empty($request->input('password'))){
                $user['password'] = bcrypt($input['password']);
            }
            $resp = User::where('id',$user_id)->update($user);
            if(!$resp){
                return $this->error("Something went wrong while updating a user");
            }else{
                return $this->success('',"User Successfully Update.");
            }
        }else{
            return $this->notLogin();
        }
    }

    public function uploadUserPic(Request $request)
    {
        // if (Auth::check()) {
            $validator = Validator::make($request->all(), [
                'picture' => 'required'
            ]);
            if ($validator->fails()) {
                $j_errors = $validator->errors();
                $errors = (array) json_decode($j_errors);
                $key = array_key_first($errors);
                return $this->error($errors[$key][0],"",422);
            }

            $user_id = Auth::id();
            $input = $request->all();
            $picture = '';

            if (!empty($input['picture'])) {
                if (preg_match('/^data:image\/(\w+);base64,/', $input['picture'])) {
                    $picture = $this->AWS_FileUpload('base64', $input['picture'],'profile');
                }
            }

            $user = User::where('id',$user_id)->update([
                "picture" => $picture,
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            if(!$user){
                return $this->error("Something went wrong while updating picture");
            }else{
                return $this->success();
            }
        /*}else{
            return $this->notLogin();
        }*/
    }
    
    // function login(Request $request)
    // {
    //     $user= User::where('email', $request->email)->first();
    //     // print_r($data);
    //         if (!$user || !Hash::check($request->password, $user->password)) {
    //             return response([
    //                 'message' => ['These credentials do not match our records.']
    //             ], 404);
    //         }
        
    //          $token = $user->createToken('my-app-token')->plainTextToken;
        
    //         $response = [
    //             'user' => $user,
    //             'token' => $token
    //         ];
        
    //          return response($response, 201);
    // }
}
