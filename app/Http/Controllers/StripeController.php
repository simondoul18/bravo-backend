<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Traits\ApiResponser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use App\Models\Stripe_connect_account;
use App\Helpers\Helper;
use App\Models\Stripe_account;
use App\Models\Stripe_payout;
use App\Models\Stripe_payment;
use App\Models\Booking;
use Illuminate\Support\Facades\DB;
use App\Services\StripeApis;
use Stripe;


class StripeController extends Controller
{
	private StripeApis $stripe;
    use ApiResponser;

	public function __construct(StripeApis $stripe)
    {
        $this->stripe = $stripe;
    }

    function addPaymentMethod(Request $request){
		$user = Auth::user();
		if (empty($user)) {
			return $this->error("Please login to continue.");
		}
		$param = $request->all();
		if(empty($param) || empty($param['token']) || empty($param['token']['id']))
		{
			return $this->error("Required Information is missing e.g Card Details.");
		}
		Stripe\Stripe::setApiKey(env('STRIPE_TEST_SECRET_KEY'));
		$customer = $this->getCustomerStripeAccount($user->id);
		if(empty($customer)){
			try {
				$customer = \Stripe\Customer::create([
					'email' => $user->email,
					'source' => $param['token']['id']
				]);
				$customer_id = $customer->id;
				
			}catch (\Stripe\Exception\RateLimitException $e) {
				return $this->error($e->getError()->message."! Too many requests made to the API too quickly");
			} catch (\Stripe\Exception\InvalidRequestException $e) {
				return $this->error($e->getError()->message."! Invalid parameters were supplied to Stripe's API");
			} catch (\Stripe\Exception\AuthenticationException $e) {
				return $this->error($e->getError()->message."! Authentication with Stripe's API failed");
			} catch (\Stripe\Exception\ApiConnectionException $e) {
				return $this->error($e->getError()->message."! Network communication with Stripe failed");
			}catch (\Stripe\Exception\CardException $e) {
				return $this->error($e->getError()->message);
			} catch (\Stripe\Exception\ApiErrorException $e) {
				return $this->error("Something Went Wrong! Please try later");
			}
			
		}else{
			try {
				$sourceCard  =  \Stripe\Customer::createSource(
					$customer->customer_id,
					['source' => $param['token']['id']]
				);
				$customer_id =  $customer->customer_id;

			}catch (\Stripe\Exception\RateLimitException $e) {
				return $this->error($e->getError()->message."! Too many requests made to the API too quickly");
			} catch (\Stripe\Exception\InvalidRequestException $e) {
				return $this->error($e->getError()->message."! Invalid parameters were supplied to Stripe's API");
			} catch (\Stripe\Exception\AuthenticationException $e) {
				return $this->error($e->getError()->message."! Authentication with Stripe's API failed");
			} catch (\Stripe\Exception\ApiConnectionException $e) {
				return $this->error($e->getError()->message."! Network communication with Stripe failed");
			}catch (\Stripe\Exception\CardException $e) {
				return $this->error($e->getError()->message);
			} catch (\Stripe\Exception\ApiErrorException $e) {
				return $this->error("Something Went Wrong! Please try later");
			}
			
		}

		$insertStripeArray  = array(
			'user_id'=>$user->id,
			'account_type'=>$param['token']['type'],
			'customer_id'=>$customer_id, 
			'source_id'=>$param['token']['card']['id'], 
			'brand'=>$param['token']['card']['brand'], 
			'card_type'=>$param['token']['card']['funding'],
			'last_four'=>$param['token']['card']['last4'],
			'is_default'=>0,
			'billing_name'=>$param['name'],
			'billing_email'=>$user->email,
			'billing_address'=>$param['address'],
			'billing_city'=>$param['token']['card']['address_city'],
			'billing_state'=>$param['token']['card']['address_state'],
			'billing_zip'=>$param['token']['card']['address_zip'],
			'billing_country'=>$param['token']['card']['address_country']
		);	

		$CardAndCustomerAdded = Stripe_account::insert($insertStripeArray);

		if($CardAndCustomerAdded){
			return $this->success("You card has been Successfully Added");
		}else{
			return $this->error("Something Went Wrong! While DB Trip");
		}
		

	}
	function getCustomerStripeAccount($id = ""){
		if(empty($id)){
			$id = Auth::id();
		}
		return Stripe_account::where('user_id',$id)->where('status',1)->first();     
	}

    public function connectStripeAccount(Request $request)
    {
		$user = Auth::user();
		if (empty($user)) {
			return $this->error("Please login to continue.");
		}

		$code = $request->code;
		
		if (empty($code)) {
			return $this->error("Something is missing.");
		}


		\Stripe\Stripe::setApiKey(env('STRIPE_TEST_SECRET_KEY'));

		try {
			$data = \Stripe\OAuth::token([
				'grant_type' => 'authorization_code',
				'code' => $code,
			]);

			// Access the connected account id in the response
			$connected_account_id = $data->stripe_user_id;

		}catch (Exception $e) {
			return $this->error($e->getError()->message."! Something went wrong with Authorization code");
		} 
		
		if(!empty($data->error)){
			$error_desc = !empty($data->error_description)?$data->error_description:"";
			return $this->error($error_desc);
		}

		$businessInfo = Helper::getBusinessByUserId($user->id);
		
		$stripe_connect = Stripe_connect_account::create([
				'busienss_id'=>$businessInfo->id,
				'access_token'=>$data->access_token,
				'refresh_token'=>$data->refresh_token,
				'token_type'=>$data->token_type,
				'stripe_publishable_key'=>$data->stripe_publishable_key,
				'stripe_user_id'=> $connected_account_id,
				'scope'=>$data->scope,
				'updated_at' => date('Y-m-d H:i:s'),
				'created_at' => date('Y-m-d H:i:s'),
		]);
		if(!$stripe_connect){
			return $this->error("Something Went Wrong! While DB Trip");
		}
		return $this->success("Stripe is connected Successfully");
    }

    function checkStripeConnectAccount(){
    	$checkStripe = 0;
		$status = "";
    	if (Auth::check()) {
            $user_id = Auth::id();
            $business = Helper::getBusinessByUserId($user_id);
            $stripeConnectAccount = Stripe_connect_account::where('business_id',$business->id)->first();
			if(!empty($stripeConnectAccount))
			{
				$checkStripe = 1;
				$stripe_user_id = $stripeConnectAccount->stripe_user_id;
				$stripe = new \Stripe\StripeClient(env('STRIPE_TEST_SECRET_KEY'));
				$accountInfo = $stripe->accounts->retrieve(
					$stripe_user_id
				);

				if($accountInfo->payouts_enabled == true){
					$status = "Completed";
				}else{
					$status = "Pending";
				}
			}
        }
    	$res = ["connected"=>$checkStripe,"status" =>$status];
    	return $this->success($res);
    }

	 /**
	 * get Business Payout.
	 *
	 * @param  \Illuminate\Http\Request  $request
	 * @return \Illuminate\Http\Response
	*/
	public function dt_payout(Request $request)
	{
		
		$input = $request->all();

        $order_columns = ['account_type','payout_amount','status','created_date','arrival_date'];

        $payout_query = $this->payout_query($input);

        // Order By
        if (isset($input['order'][0]['column']) && isset($order_columns[$input['order'][0]['column']])) {
            $payout_query->orderBy($order_columns[$input['order'][0]['column']],$input['order'][0]['dir']);
        }else{
            $payout_query->orderBy('id', 'DESC');
        }

        // Offset, Limit
        if ($input['start'] >= 0  && $input['length'] >= 0) {
            $payout_query->offset($input['start']);
            $payout_query->limit($input['length']);
        }

        $payouts = $payout_query->get();
		

		$counter = DB::table("stripe_payouts")
        ->select(
            DB::raw('SUM(CASE WHEN status = 0 THEN payout_amount ELSE 0 END) as pending_amount'),
            DB::raw('SUM(CASE WHEN status = 1 THEN payout_amount ELSE 0 END) as deposit_amount'),
			DB::raw('SUM(CASE WHEN status = 2 THEN payout_amount ELSE 0 END) as processed_amount')
        )->where('business_id', '=', $input['business_id'])->first();

        $counters = [
        	'pending_amount' => round($counter->pending_amount,2),
        	'deposit_amount' => round($counter->deposit_amount,2),
			'processed_amount' => round($counter->processed_amount,2)
        	
        ];


        $data = [];
        if(!empty($payouts) && count($payouts) > 0){
            foreach ($payouts as $key => $payout) {
                $output = [];
                $output[] = $payout->account_type;
				$output[] = $payout->payout_amount;
                $status= '';
                if ($payout->status == '0') {
                	$status = "Pending";
                }elseif ($payout->status == '1') {
                	$status = 'Paid';
                }else{
					$status = 'In Transit';
				}
                $output[] = $status;
                $output[] = date('m/d/Y H:i a',strtotime($payout->created_date));
				$output[] = "You will recieve your money on ".date('m/d/Y H:i a',strtotime($payout->arrival_date));

                $data[] = $output;
            }
        }
	
		$outsfsput = [
			'recordsTotal'=> $this->all_payouts_count($input),
			'recordsFiltered'=> $this->filtered_payouts_count($input),
			"data"=>$data,
			"stats" => $counters
		];
		if (empty($input['platform'])) {
			$outsfsput["draw"]= $input['draw'];
		}
        return response()->json($outsfsput, 200);

	}

    public function payout_query($input){
	 	$query = Stripe_payout::where('business_id', '=', $input['business_id']);
	 	// if (!empty($input['business_id'])) {
	 	// 	$query->where('business_id', '=', $input['business_id']);
	 	// }
	 
		if(!empty($input['type'])){
			$query->where('status',$input['type']);
		}

	 	//Duration
	 	if (!empty($input['start_date']) && !empty($input['end_date'])) {
	 		if ($input['start_date'] == $input['end_date']) {
	 			$query->whereDate('stripe_payouts.created_at', '=', $input['start_date'] );
	 		}else{
		 		$query->whereBetween('stripe_payouts.created_at', [$input['start_date'],$input['end_date']]);
	 		}
	 	}elseif (!empty($input['start_date'])) {
	 		$query->whereDate('stripe_payouts.created_at', '>', $input['start_date'] );
	 	}elseif (!empty($input['end_date'])) {
	 		$query->whereDate('stripe_payouts.created_at', '<', $input['end_date'] );
	 	}

        return $query;
    }
    public function all_payouts_count($input){
        return $q = Stripe_payout::where("business_id",$input["business_id"])->count();
    }
    public function filtered_payouts_count($input){
        $query = $this->payout_query($input);
        return $query->count();
    }

	 /**
	 * get Business Payout.
	 *
	 * @param  \Illuminate\Http\Request  $request
	 * @return \Illuminate\Http\Response
	*/
	public function dt_payment_history (Request $request)
	{
		
		$input = $request->all();

        $order_columns = ['booking_ref','net_amount','available_on','created','status'];
		// ,'total_amount','total_fee','application_fee_amount','status'

        $payment_query = $this->payment_query($input);

        // Order By
        if (isset($input['order'][0]['column']) && isset($order_columns[$input['order'][0]['column']])) {
            $payment_query->orderBy($order_columns[$input['order'][0]['column']],$input['order'][0]['dir']);
        }else{
            $payment_query->orderBy('id', 'DESC');
        }

        // Offset, Limit
        if ($input['start'] >= 0  && $input['length'] >= 0) {
            $payment_query->offset($input['start']);
            $payment_query->limit($input['length']);
        }

        $payments = $payment_query->get();
		
        $data = [];
        if(!empty($payments) && count($payments) > 0){
            foreach ($payments as $key => $payment) {
                $output = [];
                $output[] = $payment->booking_ref;
				$output[] = $payment->net_amount;

				$arivalDate = strtotime($payment->available_on);
				$todayDate = strtotime(date("Y-m-d"));

				$status= '';
				if ($payment->payment_status != 'succeeded') {
                	$status = $payment->payment_status;
                }else{
					$status = $payment->amount_status;
				}

				$message = "Paymen is incompleted";

				if($arivalDate ==  $todayDate && $payment->payment_status == "succeeded")
				{
					$message = "Your funds are expected to be deposited today";
				}
				else if($arivalDate < $todayDate && $payment->payment_status == "succeeded")
				{
					$message = "Your funds are deposited and available for payout";
					$status = "Available";
				}else if($arivalDate > $todayDate && $payment->payment_status == "succeeded")
				{
					$message = "Your funds will be available for payout soon";
					$message .= "! Expected on".date('m-d-Y',strtotime($payment->available_on));
				}


				$output[] = $message;
				$output[] = date('m/d/Y H:i a',strtotime($payment->created));

                
                $output[] = $status;
            
				// $output[] = $payment->total_amount;
				// $output[] = $payment->total_fee;
				// $output[] = $payment->application_fee_amount;

                $data[] = $output;
            }
        }
	
		$outsfsput = [
			'recordsTotal'=> $this->all_payment_count($input),
			'recordsFiltered'=> $this->filtered_paymets_count($input),
			"data"=>$data
		];
		if (empty($input['platform'])) {
			$outsfsput["draw"]= $input['draw'];
		}
        return response()->json($outsfsput, 200);

	}

	public function payment_query($input){
		$query = Stripe_payment::where('business_id', '=', $input['business_id']);
	    return $query;
    }

    public function all_payment_count($input){
		return $q = Stripe_payment::where("business_id",$input["business_id"])->count();
	}
	public function filtered_paymets_count($input){
		$query = $this->payment_query($input);
		return $query->count();
	}


	public function StripeEventWebhook(Type $var = null)
	{
		// This is your Stripe CLI webhook secret for testing your endpoint locally.
		$endpoint_secret = 'whsec_6a7ff62af9be00d34d51b05551991649502ccd0a4635bc0cdc082be1918167ad';

		$payload = @file_get_contents('php://input');
		$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];
		$event = null;

		try {
		$event = \Stripe\Webhook::constructEvent(
			$payload, $sig_header, $endpoint_secret
		);
		} catch(\UnexpectedValueException $e) {
		// Invalid payload
		http_response_code(400);
		exit();
		} catch(\Stripe\Exception\SignatureVerificationException $e) {
		// Invalid signature
		http_response_code(400);
		exit();
		}

		// Handle the event
		switch ($event->type) {
		case 'payment_intent.succeeded':
			$paymentIntent = $event->data->object;
		// ... handle other event types
		default:
			echo 'Received unknown event type ' . $event->type;
		}

		http_response_code(200);
	}

	public function connectAccountDetails()
	{
		$stripe =  Stripe\Stripe::setApiKey(env('STRIPE_TEST_SECRET_KEY'));
		$balance = \Stripe\Balance::retrieve(
			['stripe_account' => 'acct_1LvO5GRcUXtsO02E']
		);
		$availableBlances = 0;
		if(!empty($balance))
		{
			$availableBlance = $balance->available;
			$availableBlances = $availableBlance[0]->amount/ 100;
		}

		
		$res = ["data"=>$availableBlances];
    	return $this->success($res,"You card has been Successfully Added");
	}

	public function payout(Request $request)
	{
		if (Auth::check()) {
			$input = $request->all();
			if(empty($input['amountToBeWithdraw']))
			{
				return $this->error("Minimum withdrawl amount is $1");
			}
			$user_id = Auth::id();
            $business = Helper::getBusinessByUserId($user_id);
			$response = $this->stripe->StripeWithdraw($input['amountToBeWithdraw'],$business->id);
			if($response['status'] == false)
			{
				return $this->error(" Something Went wrong! ".$response['message']);
			}

			$payoutInfo = $response["data"];
			
			$status =  !empty($statuses[$payoutInfo->status])?$statuses[$payoutInfo->status]:2;


			$payout_details = [
				'business_id' => $business->id,
				'payout_id' => !empty($payoutInfo->id)?$payoutInfo->id:"",
				'account_type'=>!empty($payoutInfo->type)?$payoutInfo->type:"",
				'balance_transaction' => !empty($payoutInfo->balance_transaction)?$payoutInfo->balance_transaction:"",
				'payout_amount' => !empty($payoutInfo->amount)?$payoutInfo->amount:0,
				"destination_bank" => !empty($payoutInfo->destination)?$payoutInfo->destination:"",
				'status' =>$status,
				'created_date' => !empty($payoutInfo->created)?date("Y-m-d H:i",$payoutInfo->created):"",
				'arrival_date' => !empty($payoutInfo->arrival_date)?date("Y-m-d H:i",$payoutInfo->arrival_date):"",
				'updated_at' => date('Y-m-d H:i:s'),
				'created_at' => date('Y-m-d H:i:s')
			];

			Stripe_payout::create($payout_details);

			return $this->success("Congratulations! our funds are on the Way");

		}
		return $this->error("Not Authenticated");
		
	}

	public function test(Type $var = null)
	{
		$stripe = new \Stripe\StripeClient(env('STRIPE_TEST_SECRET_KEY'));

		try {
			
			// $transferInfo = $stripe->transfers->create([
			// 	'amount' => 1*100,
			// 	'currency' => 'usd',
			// 	'destination' => "acct_1LvO5GRcUXtsO02E",
			// 	'transfer_group' => 'Ondaq-Transfer',
			// ]);
			// $data['data'] = $transferInfo;
			// $data['message'] = "Payment has been charged Successfully";
			// $data['status'] = true;

			// $transactionsArr = array(
			// 	"amount" =>1*100,
			// 	"currency" => "usd",
			// 	"description" =>  "You have booked a service"
			// );
			// $transactionsArr['source'] =  "card_1MAe9AD3qdCEG0Ev5UwPRcBD";
			// $transactionsArr['customer'] =  "cus_MuT55nCPg7gskO";
			// $response = $stripe->charges->create($transactionsArr);
			// $data['data'] = $response;
			// $data['message'] = "Payment has been charged Successfully";
			// $data['status'] = true;
			
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

		echo "Payment has been charged Successfully";
		echo "<pre>";
		print_r($data['message']);
		print_r($data['data']);
		exit;
	}

	public function getSripeWebhooks()
	{
		$this->stripe->StripeWebhook();
		
	}

	function testing(){
		$UpdateBooking = Booking::where('id',"81")->update(['tracking_id' => "Khan",'color'=>"000"]);
	}

	
}