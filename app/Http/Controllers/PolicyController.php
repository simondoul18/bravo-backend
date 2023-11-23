<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Traits\ApiResponser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use App\Helpers\Helper;

use App\Models\Policy;

class PolicyController extends Controller
{
    use ApiResponser;

    public function updatePolicy(Request $request){
        if (Auth::check()) {
            $validator = Validator::make($request->all(), [
                'type' => 'required',
                'modal_type' => 'required',
                'business_id' => 'required',
                'id' => 'required',
            ]);
            if ($validator->fails()) {
                $j_errors = $validator->errors();
                $errors = (array) json_decode($j_errors);
                $key = array_key_first($errors);
                return $this->error($errors[$key][0],"",422);
            }

            $input = $request->all();
            $user_id = Auth::id();

            // Validate conditional fields
            if ($input['type'] == 2 && empty($input['charge_val'])) {
                return $this->error("Please add amount value.","",422);
            }

            $upd_arr = [
                'user_id' => $user_id,
                'description' => '',
                'policy_condition' => $input['type'],
                'policy_type' => $input['modal_type'],
                'duration' => null,
                'charge_amount' => null,
                //'duration' => empty($input['duration']) ? null:$input['duration'],
                //'charge_amount' => $input['type'] == 2 ? $input['charge_val']:'',
                'updated_at' => date('Y-m-d H:i:s')
            ];
            if (!empty($input['duration']) && $input['modal_type'] == 'cancel' && $input['type'] == 2) {
                $upd_arr['duration'] = $input['duration'];
            }
            if (!empty($input['charge_val']) && $input['type'] == 2) {
                $upd_arr['charge_amount'] = $input['charge_val'];
            }

            if ($input['modal_type'] == 'cancel') {
                if ($input['type'] == 1) {
                    $upd_arr['description'] = "The customer can cancel their booking without any charge.";
                }elseif ($input['type'] == 2) {
                    if ($input['duration'] == 1) {
                        $duration = '24hours';
                    }elseif ($input['duration'] == 2) {
                        $duration = '48hours';
                    }else{
                        $duration = $input['duration'].' Days';
                    }
                    $upd_arr['description'] = "The customer can cancel free of charge until ".$duration." after booking. The customer will be charged ".$input['charge_val']."% of the total price if they cancel after ".$duration." of booking.";
                }
            }elseif ($input['modal_type'] == 'no-show') {
                if ($input['type'] == 1) {
                    $upd_arr['description'] = "There will be no charge if customer don't arrived.";
                }elseif ($input['type'] == 2) {
                    $upd_arr['description'] = "If the customer doesn't show up they will be charged ".$input['charge_val']."% of the total price.";
                }
            }

            $resp = Policy::where('id',$input['id'])->where('business_id',$input['business_id'])->update($upd_arr);
            
            if ($resp) {
                return $this->success('','Policy successfully added.');
            }else{
                return $this->error('Something went wrong while adding policy.');
            }
        }else{
            return $this->notLogin();
        }
    }

    public function getPolicies($id){
        $policies = Policy::where('business_id', $id)->orderBy('id','asc')->get();
        return $this->success($policies);
    }
    // public function getPolicies($id){
    //     $data = [
    //         'cancel'=>[],
    //         'noshow'=>[]
    //     ];
    //     $policies = Policy::where('business_id', $id)->orderBy('duration','asc')->get();

    //     if (!empty($policies) && count($policies) > 0) {
    //         foreach ($policies as $key => $policy) {
    //             if ($policy->policy_type == 'cancel') {
    //                 $data['cancel'][] = $policy;
    //             }elseif ($policy->policy_type == 'noshow') {
    //                 $data['noshow'][] = $policy;
    //             }
    //         }
    //     }
    //     return $this->success($data);
    // }

    public function getPolicyStatement($id,$types=''){
        if (!is_int($id)) {
            $business = Helper::getBusinessBySlug($id,1);
            if (!empty($business)) {
                $id = $business->id;
            }else{
                return $this->error("Business not found.");
            }
        }

        $q = Policy::where('business_id', $id)->orderBy('policy_type','asc');
        if (!empty($types)) {
            $q->where(function($query) use($types) {
                $types_arr = explode("_",$types);
                foreach ($types_arr as $key => $value) {
                    if ($key == 0) {
                        $query->where('policy_type',$value);
                    }else{
                        $query->orWhere('policy_type',$value);
                    }
                }
            });
        }
        $policies = $q->get();

        $statement = '';
        if (!empty($policies) && count($policies) > 0) {
            foreach ($policies as $key => $policy) {
                $statement .= $policy->description.'<br>';
            }
        }
        return $this->success($statement);
    }

    public function deletePolicy($id,$business_id){
        $resp = Policy::where('business_id',$business_id)->where('id',$id)->delete();
        if ($resp) {
            return $this->success("","Sucessfully deleted.");
        }else{
            return $this->error("Sorry! Service did not deleted.");
        }
    }
}