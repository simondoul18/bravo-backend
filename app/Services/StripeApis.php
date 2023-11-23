<?php
/**
	Author : Mehran Shah
*/


namespace App\Services;
use App\Helpers\Helper;
use Stripe;

class StripeApis
{
    protected $secretKey;
    protected $stripeClient;
    protected $stripeSetApiKey;

    public function __construct()
    {
        //$this->secretKey = env('STRIPE_LIVE_SECRET_KEY'); //Live
		$this->secretKey = env('STRIPE_TEST_SECRET_KEY'); // Sandbox
        $this->stripeClient = new \Stripe\StripeClient($this->secretKey);
        $this->stripeSetApiKey = Stripe\Stripe::setApiKey($this->secretKey);
    }

	public function chargeCustomer($amount = 0, $source = 0, $platformFee = 0, $businessId= 0 )
    {
    	$data = ["data"=>[],"message"=>"","status"=>false];
    	if(empty($amount) ||  empty($source))
    	{
    		$data["message"] = "Something is missing! Amount or payment source";
    		return $data;
    	}

    	//Authorise the source 
    	$paymentSourceDetails = Helper::getUserStripePaymentSource($source);
    	if($paymentSourceDetails['status'] == false)
    	{
    		$data["message"] = $paymentSourceDetails['message'];
    		return $data;
    	}

    	$souceDetails = !empty($paymentSourceDetails['data'])?$paymentSourceDetails['data']:[];

    	$paymentCustomerId = !empty($souceDetails->customer_id)?$souceDetails->customer_id:0;
    	$paymentSourceId = !empty($souceDetails->source_id)?$souceDetails->source_id:0;
		$ondaq_fee = !empty($bookingInfo["ondaq_fee"])? (float) $bookingInfo["ondaq_fee"]:0;
		$businessConnectAccountId = ""; 

		$businessId = !empty($businessId)? (float) $businessId:0;
		//check if business stripe account is connected
		$businessStripeConnectAccount = Helper::getBusinessStripeConnectAccount($businessId);
		if($businessStripeConnectAccount['status'] == true)
		{
			$businessConnectAccountInfo = $businessStripeConnectAccount["data"];
			$businessConnectAccountId = !empty($businessConnectAccountInfo->stripe_user_id)?$businessConnectAccountInfo->stripe_user_id:"";
		}

    	//Charge a customer 
    	$createPaymentIntent = $this->StripePaymentIntentObject($paymentCustomerId,$paymentSourceId,$amount,$platformFee,$businessConnectAccountId );

    	if($createPaymentIntent['status'] == false )
    	{
    		$data["message"] = $createPaymentIntent['message'];
    		return $data;
    	}

    	$data["data"] = !empty($createPaymentIntent['data'])?$createPaymentIntent['data']:[];
    	$data["message"] = $createPaymentIntent['message'];
		$data["status"] = true;

    	return $data;
    }

    public function chargeCard($amount= 0 , $source = 0)
    {
    	$data = ["data"=>[],"message"=>"","status"=>false];
    	if(empty($amount) || empty($source))
    	{
    		$data["message"] = "Something is missing! Amount or payment source";
    		return $data;
    	}

    	//Authorise the source 
    	$paymentSourceDetails = Helper::getUserStripePaymentSource($source);
    	if($paymentSourceDetails['status'] == false)
    	{
    		$data["message"] = $paymentSourceDetails['message'];
    		return $data;
    	}

    	$souceDetails = !empty($paymentSourceDetails['data'])?$paymentSourceDetails['data']:[];

    	$paymentCustomerId = !empty($souceDetails->customer_id)?$souceDetails->customer_id:0;
    	$paymentSourceId = !empty($souceDetails->source_id)?$souceDetails->source_id:0;

    	//Charge a customer 
    	$createCharge = $this->StripeChargeObject($paymentCustomerId , $paymentSourceId , $amount);

    	if($createCharge['status'] == false )
    	{
    		$data["message"] = $createCharge['message'];
    		return $data;
    	}
		$data["status"] = true;
    	$data["data"] = !empty($createCharge['data'])?$createCharge['data']:[];
    	$data["message"] = $createCharge['message'];

    	return $data;
    }

  	public function StripeChargeObject($paymentCustomerId = 0, $paymentSourceId = 0, $amount = 0)
	{
		$data = ["data"=>[],"message"=> "","status"=>false];
		
		if(empty($paymentCustomerId) || empty($paymentSourceId) || empty($amount))
		{
			$data['message'] = "Required Fields are missing e.g (source_id , customer_id , amount)";
			return $data;
		}

		//convert amount into cents
		$amount = $amount * 100 ;

		try {

			$chargesDetails = [
				"amount" => $amount, //Amount to be charged in cents
				"customer" => $paymentCustomerId, //payeer id
				"source" => $paymentSourceId, // source id of the payeer 
				"currency" => "usd",
				"description" =>  "Subscription",

			];

			$response = $this->stripe->charges->create($chargesDetails);
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
			$data['message'] = "Something Went wrong! Please try agian";
		} 
		
		$this->logStripeResponse([], $data);
		return $data;

	}

	public function transferBusinessPayment($amount = 0, $businessId = 0)
	{
		$data = ["data"=>[],"message"=> "","status"=>false];
		if(empty($amount) || empty($businessId))
		{
			$data['message'] = "Required Fields are missing e.g (business_id , amount)";
			return $data;
		}

		//Get business connect Acount
		$businessConnectAccount = Helper::getBusinessByUserId($businessId);
    	if($businessConnectAccount['status'] == false)
    	{
    		$data["message"] = $businessConnectAccount['message'];
    		return $data;
    	}

    	$connectAccount = !empty($businessConnectAccount['data'])?$businessConnectAccount['data']:[];

    	$stripeConectAccountUserId = !empty($connectAccount->stripe_user_id)?$souceDetails->stripe_user_id:0;

    	//Charge a customer 
    	$transferCreate = $this->StripeTransferFunds($stripeConectAccountUserId , $amount);

    	if($createCharge['status'] == false )
    	{
    		$data["message"] = $createCharge['message'];
    		return $data;
    	}

    	$data["data"] = !empty($createCharge['data'])?$createCharge['data']:[];
    	$data["message"] = $createCharge['message'];

    	return $data;

	}

	public function StripeTransferFunds($stripeConectAccountUserId = 0 , $amount=0)
	{
		$data = ["data"=>[],"message"=> "","status"=>false];
		if(empty($stripeConectAccountUserId) || empty($amount))
		{
			$data["message"] = "Required Fields are missing e.g (stripeConectAccountUserId , amount)";
			return $data;
		}

		//convert amount into cents
		$amount = $amount * 100 ;

		try {

			$transferDetails = [
				'amount' => $amount,
				'destination' => $stripeConectAccountUserId,
				'currency' => 'usd',
				'transfer_group' => 'Ondaq-Booking-Payments'
			];

			$response = $this->stripe->transfers->create();
			$data['data'] = $response;
			$data['message'] = "Payment has been transfered Successfully";
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

		$this->logStripeResponse([], $data);
		return $data;
		
	}

	public function StripeWithdraw($amount = 0, $businessId = 0)
	{
		$data = ["data"=>[],"message"=> "","status"=>false];
		if(empty($amount) || empty($businessId))
		{
			$data['message'] = "Required Fields are missing e.g (business_id , amount)";
			return $data;
		}

		//Get business connect Acount
		$businessConnectAccount = Helper::getBusinessStripeConnectAccount($businessId);
    	if($businessConnectAccount['status'] == false)
    	{
    		$data["message"] = $businessConnectAccount['message'];
    		return $data;
    	}

    	$connectAccount = !empty($businessConnectAccount['data'])?$businessConnectAccount['data']:[];

    	$stripeConectAccountUserId = !empty($connectAccount->stripe_user_id)?$souceDetails->stripe_user_id:0;

    	//check Stripe Connect Available Balance
    	$availableBlance = $this->StripeAccountBalance($stripeConectAccountUserId);

    	if($availableBlance['status'] == false )
    	{
    		$data["message"] = $availableBlance['message'];
    		return $data;
    	}

    	$stripeAccountBalance = !empty($availableBlance['data'])?$availableBlance['data']:[];
    	$availableAmount = !empty($stripeAccountBalance[0] && $stripeAccountBalance[0]->amount )?$stripeAccountBalance[0]->amount:0;
    	$availableAmount = !empty($availableAmount)?$availableAmount/100:0;

    	if(empty($availableAmount) || $availableAmount < $amount)
		{
			$data["message"] = "You don't have enough funds to withdraw";
			return $data;
		}

		//payout amount to the stripe acount
    	$payoutInfo = $this->StripePayouts($stripeConectAccountUserId , $amount);

    	if($payoutInfo['status'] == false )
    	{
    		$data["message"] = $payoutInfo['message'];
    		return $data;
    	}

    	$data["data"] = !empty($payoutInfo['data'])?$payoutInfo['data']:[];
    	$data["message"] = $payoutInfo['message'];

    	return $data;

	}


	public function StripeAccountBalance($stripeConectAccountUserId = 0)
	{
		$data = ["data"=>[],"message"=> "","status"=>false];
		if(empty($stripeConectAccountUserId))
		{
			$data['message'] = "Required Fields are missing e.g (stripeConectAccountUserId)";
			return $data;
		}

		try {

			$balance = \Stripe\Balance::retrieve(
				['stripe_account' => $stripeConectAccountUserId]
			);
			$data['data'] = $balance;
			$data['status'] = true;
			$data['message'] = "Balance retrieved success";
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
			$data['message'] = "Something Went wrong! Please try agian";
		} 

		$this->logStripeResponse([], $data);
		return $data;
	}

	public function StripeAccountBalanceTransaction($balanceTransactionId = 0)
	{
		$data = ["data"=>[],"message"=> "","status"=>false];
		if(empty($balanceTransactionId))
		{
			$data['message'] = "Required Fields are missing e.g (balanceTransactionId)";
			return $data;
		}

		try {
			
			$response =  $this->stripeClient->balanceTransactions->retrieve($balanceTransactionId);
			$data['data'] = $response;
			$data['status'] = true;
			$data['message'] = "Balance transaction retrieved Successfully";
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
			$data['message'] = "Something Went wrong! Please try agian";
		} 
		$this->logStripeResponse([], $data);
		return $data;
	}

	public function StripePayouts($stripeConectAccountUserId = 0 , $amount = 0)
	{
		$data = ["data"=>[],"message"=> "","status"=>false];
		if(empty($stripeConectAccountUserId) || empty($amount))
		{
			$data['message'] = "Required Fields are missing! StripePayouts e.g (stripeConectAccountUserId , amount)";
			return $data;
		}

		//convert amount into cents
		$amount = $amount * 100 ;

		try {

			$response = \Stripe\Payout::create(
				[
					'amount' => $amount,
					'currency' => 'usd',
				], 
				[
					'stripe_account' => $stripeConectAccountUserId,
				]
			);
			
			$data['data'] = $response;
			$data['status'] = true;
			$data['message'] = "Stripe Payout Done Successfully";
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
			$data['message'] = "Something Went wrong! Please try agian";
		} 

		$this->logStripeResponse([], $data);
		return $data;
	}

	public function StripeWebhook(Type $var = null)
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
		} 
		catch(\UnexpectedValueException $e) 
		{
			// Invalid payload
			http_response_code(400);
			exit();
		} 
		catch(\Stripe\Exception\SignatureVerificationException $e)
		{
			// Invalid signature
			http_response_code(400);
			exit();
		}

		// Handle the event
		switch ($event->type) 
		{
			case 'balance.available':
				$balance = $event->data->object;
				$this->updateBusinessTranfers($balance);
			case 'charge.succeeded':
				$charge = $event->data->object;
			case 'payment_intent.succeeded':
				$paymentIntent = $event->data->object;
			default:
			  echo 'Received unknown event type ' . $event->type;

		}
		
	}

	public function updateBusinessPendingFunds($balance)
	{
		$data = ["data"=>[],"message"=> "","status"=>false];
		if(empty($balance))
		{
			$data['message'] = "Something is missing";
			return $data;
		}

		$transfer_details = [
			'transfer_id'=>!empty($transferInfo->id)?$transferInfo->id:"",
			"destination_account" => !empty($transferInfo->destination)?$transferInfo->destination:"",
			'destination_payment' =>!empty($transferInfo->destination_payment)?$transferInfo->destination_payment:"",
			'source_type' =>!empty($transferInfo->source_type)?$transferInfo->source_type:"",
			'created' => !empty($transferInfo->created)?date("Y-m-d H:i",$transferInfo->created):"",
			'object' => !empty($transferInfo->object)?$transferInfo->object:"",
			'amount_reversed' => !empty($transferInfo->amount_reversed)?$transferInfo->amount_reversed:"",
			'updated_at' => date('Y-m-d H:i:s')
		];

		Stripe_transfer::where("balance_transaction_id",$b_id)->update($transfer_details);
		$data["status"] = true;
		return $data;
	}

	public function StripePaymentIntentObject($paymentCustomerId = 0, $paymentSourceId = 0, $amount = 0 , $platformFee = 0, $destinationAccount = "")
	{
		$data = ["data"=>[],"message"=> "","status"=>false];

		if(empty($paymentCustomerId) || empty($paymentSourceId) || empty($amount))
		{
			$data['message'] = "Required Fields are missing e.g (source_id , customer_id , amount)";
			return $data;
		}

		//convert amount into cents
		$amount = $amount * 100 ;

		try {
			$paymentIntentArr = [
				'amount' => $amount,
				'currency' => 'usd',
				'customer' => $paymentCustomerId,
				'payment_method' => $paymentSourceId,
				'off_session' => true,
				'confirm' => true
			];

			if(!empty($platformFee) && !empty($destinationAccount) )
			{
				$platformFee = $platformFee * 100 ;
				$paymentIntentArr['application_fee_amount'] = $platformFee;
				$paymentIntentArr['transfer_data']['destination'] = $destinationAccount;
			}

			$response = \Stripe\PaymentIntent::create($paymentIntentArr);

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
			$data['message'] = "Something Went wrong! Please try agian";
		} 

		$this->logStripeResponse($paymentIntentArr, $data);
		
		return $data;
	}


	public function logStripeResponse($request = [] , $response = [])
	{

		$businessConnectAccount = Helper::logStripeResponse($request,$response );

	}
    


   }