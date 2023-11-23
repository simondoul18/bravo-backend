<?php

namespace App\Http\Controllers;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use App\Traits\ApiResponser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Plan;
use App\Models\Transaction;
use App\Models\Business;
use App\Models\User_setting;
use App\Services\StripeApis;

class SubscriptionPlansController extends Controller
{
    use ApiResponser;
    public function __construct(StripeApis $stripe)
    {
        $this->stripe = $stripe;
    }
    public function getPlans()
    {
        $planData = Plan::select("id","title","no_of_employees","amount")
        ->where('status',1)->orderBy('id','ASC')->get();
        return $this->success($planData);
    }
    public function getPlanDetail($business_id,$return_type='')
    {
        $businessInfo = Business::select("businesses.user_id","businesses.id","free_trial","subscription_plan_id","payment_method","plan_start_date","plan_expiry_date","businesses.created_at","plan_days",
        DB::raw("(SELECT COUNT(*) FROM employees WHERE business_id = businesses.id) as total_employees"))
        ->with(['user_settings:id,auto_renew_plan,card_for_auto_renew','user_settings.card:id,brand,card_type,last_four'])
        ->with('plan:id,no_of_employees,amount,duration')
        ->where('businesses.id',$business_id)->first();
        if ($return_type == 'return') {
            return $businessInfo;
        }
        return $this->success($businessInfo);
    }
    public function getTransactionDetail($business_id)
    {
        $paymentHistory = Transaction::where('id',$business_id)->first();
        return $this->success($paymentHistory);

    }
    public function updateAutoRenewStatus(Request $request){
        $params = $request->all();
        $id = Auth::id();
        $query = User_setting::where('user_id',$id);
        $setting = $query->first();
        if (!empty($setting)) {
            $resp = $query->update(['auto_renew_plan'=>$params['status'],'updated_at' => date('Y-m-d H:i:s')]);
        }else{
            $resp = User_setting::insert([
                'user_id' => $id,
                'auto_renew_plan'=>$params['status'],
                'updated_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s')
            ]);
        }
        if ($resp) {
            return $this->success('','Successfully update.');
        }else{
            return $this->error("Something went wrong.");
        }
    }
    public function updateAutoRenewCard(Request $request){
        $params = $request->all();
        if (empty($params['card'])) {
            return $this->error("Card id is missing.");
        }
        $id = Auth::id();
        $query = User_setting::where('user_id',$id);
        $setting = $query->first();
        if (!empty($setting)) {
            $resp = $query->update(['card_for_auto_renew'=>$params['card'],'updated_at' => date('Y-m-d H:i:s')]);
        }else{
            $resp = User_setting::insert([
                'user_id' => $id,
                'card_for_auto_renew'=>$params['card'],
                'updated_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s')
            ]);
        }
        if ($resp) {
            return $this->success('','Successfully update.');
        }else{
            return $this->error("Something went wrong.");
        }
    }
    public function updatePlan(Request $request){
        if (Auth::check()) {
            $validator = Validator::make($request->all(), [
                'card' => 'required',
                'plan.id' => 'required',
                'business_id' => 'required',
                'action' => 'required'
            ]);
            if ($validator->fails()) {
                $j_errors = $validator->errors();
                $errors = (array) json_decode($j_errors);
                $key = array_key_first($errors);
                return $this->error($errors[$key][0],"",422);
            }
            $params = $request->all();
            $id = Auth::id();

            // Gte Selected Plan
            $selected_plan = Plan::where('id',$params['plan']['id'])->first();
            if (empty($selected_plan)) {
                return $this->error("Plan not found.","",422);
            }
            // Get Current Plan Detail
            $current_plan = $this->getPlanDetail($params['business_id'],'return');
            if (empty($current_plan)) {
                return $this->error("Something went wrong.","",422);
            }
            // Validate Actions
            if ($current_plan->payment_method === 1 && $params['action'] === 'upgrade') {
                return $this->error("You are on Pay as you earn. Please subscribe first.","",422);
            }
            if ($current_plan->payment_method === 2 && $params['action'] === 'subscribe') {
                return $this->error("You allready has subscription. Don't subscribe again. Please upgrade if you want to add more employees.","",422);
            }
            // Get Selected Card
            $selected_card = DB::table("stripe_accounts")->where('user_id',$id)->where('id',$params['card'])->first();
            if (empty($selected_card)) {
                return $this->error("Payment card not found.","",422);
            }

            // Calculate amount
            if ($params['action'] === 'upgrade') {
                // this.store.plan_.plan.no_of_employees <= this.store.planData.plan.no_of_employees
                if (!empty($current_plan->plan->no_of_employees >= $selected_plan->no_of_employees)) {
                    return $this->error("Please choose upgraded plan.","",422);
                }
                $amount = $selected_plan->amount - $current_plan->plan->amount;
            }elseif ($params['action'] === 'subscribe') {
                $amount = $selected_plan->amount;
            }
            if (empty($amount)) {
                return $this->error("Something went wrong.","",422);
            }

            // Deduct payment from stripe $amount, $selected_card
            $paymentSucceded = $this->stripe->chargeCard($amount,$selected_card);
            if($paymentSucceded['status'] == false)
            {
                return $this->error($paymentSucceded['message'],"",422);
            }
            
            // Add transaction


            $data = [
                'free_trial'=>0,
                'payment_method'=>2,
                'subscription_plan_id'=>$selected_plan->id,
                'plan_days'=>30,
                'plan_start_date'=>date('Y-m-d'),
                'plan_expiry_date'=>date('Y-m-d',strtotime("+30 days")),
                'updated_at' => date('Y-m-d H:i:s')
            ];
            if ($params['action'] === 'subscribe') {
                $data['plan_start_date'] = date('Y-m-d');
                $data['plan_expiry_date'] = date('Y-m-d',strtotime("+30 days"));
            }
            $resp = Business::where('id',$params['business_id'])->update($data);
            if ($resp) {
                User_setting::where('user_id',$id)->update([
                    'card_for_auto_renew'=>$params['card'],
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
                $msg = "Subscription successfull.";
                if ($params['action'] === 'upgrade'){$msg = "Plan Successfully Upgrade.";} 
                return $this->success('',$msg);
            }else{
                return $this->error("Something went wrong.");
            }
        }else{
			return $this->notLogin();
		}
    }
}
