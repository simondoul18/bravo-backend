<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Traits\ApiResponser;


use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

use App\Models\Email_campaign;

//use App\User;
//use App\Lead;
//use App\UserTemplate;
//use App\Customer;
class CampaignsController extends Controller
{
    use ApiResponser;

    public function dt_campaigns(Request $request){
        $input = $request->all();
        if(empty($input['business_id'])){
            return $this->error("Business id is missing.");
        }

        $order_columns = ['id','name','subject','status','date'];
        $query = Email_campaign::where('business_id',$input['business_id']);


        if (!empty($input['search']['value'])) {
            $search_val = "%".$input["search"]["value"]."%";
            $query->where('name', 'like', $search_val);
        }
        if (!empty($input['order'][0]['column']) && !empty($order_columns[$input['order'][0]['column']])) {
            $query->orderBy($order_columns[$input['order'][0]['column']],$input['order'][0]['dir']);
        }else{
            $query->orderBy('id', 'DESC');
        }

        if ($input['start'] >= 0  && $input['length'] >= 0) {
            $query->offset($input['start']);
            $query->limit($input['length']);
        }
        
        $campaigns = $query->get();
        //return response()->json(['success'=>$leads], $this->successStatus); exit;
        $data = [];
        if(!empty($campaigns) && count($campaigns) > 0){
            foreach ($campaigns as $key => $camp) {
                $output = [];
                $output[] = $key+1;
                $output[] = $camp->name;
                $output[] = $camp->subject;
                if($camp->status == '1'){
                    $output[] = '<a  class="solds clStatus" id="statusChange'.$camp->id.'" data-id="'.$camp->id.'" data-status="'.$camp->status.'" href="#">Active</a>';
                }else{
                    $output[] = '<a  class="losts clStatus" id="statusChange'.$camp->id.'" data-id="'.$camp->id.'" data-status="'.$camp->status.'" href="#">InActive</a>';
                }
                $output[] = date('m-d-Y H:i A',strtotime($camp->created));
                $output[] = '<a  class="assin clView" data-id="'.$camp->id.'" href="#">View</a>';
                $data[] = $output;
            }
        }

        $outsfsput = [
            "draw"=>$input['draw'],
            'recordsTotal'=> $this->get_all_campaigns_count(),
            'recordsFiltered'=> $this->get_all_campaigns_filter_data_count($input['business_id']),
            "data"=>$data
        ];
        return response()->json($outsfsput, $this->successStatus);

    }
    public function get_all_campaigns_count(){
        return Email_campaign::count();
    }
    public function get_all_campaigns_filter_data_count($business_id){
        return Email_campaign::where('business_id',$business_id)->count();
    }

    // Campaign Detaail
    public function campaignDetail($id){
        $campaign = DB::table('email_campaigns')->where('id','=',$id)->first();
        return $this->success($campaign);
    }
    // Change campaign status
    public function changeCampaignStatus(Request $request){
        $validator = Validator::make($request->all(), [
			'id' => 'required', 
            'status' => 'required',
		]);
		if ($validator->fails()) {
			$j_errors = $validator->errors();
			$errors = (array) json_decode($j_errors);
			$key = array_key_first($errors);
			return $this->error($errors[$key][0],"",422);
		}

        $input = $request->all();

        if($input['status'] == '0'){
            $status = 1;
        }elseif($input['status'] == '1'){
            $status = 0;
        }
        $upd_arr = [
            'status' => $status,
            'updated_at' => date('Y-m-d H:i:s')
        ];
        $resp = Email_campaign::where('id','=',$input['id'])->update($upd_arr);
        if($resp){
            return $this->success("","Status successfully changed.");
        }else{
            return $this->error("Sorry! Something went wrong.");
        }
    }




    public $successStatus = 200;
    public $errorStatus = 200;

    // Add Campaign List Start

    public function campaignListDetail($id){
        $data = DB::table('email_campaigns_list')->where('ecl_id','=',$id)->first();
        if (!empty($data->ecl_filters)) {
            $filters = json_decode($data->ecl_filters);

            if (!empty($filters->filter_type)) {
                if ($filters->filter_type == 'by_area') {
                    if (!empty($filters->zip)) {
                        $zips = explode(',', $filters->zip);
                        $zip_arr=[];
                        foreach ($zips as $key => $value) {
                            $zip_arr[] = ['id'=>$value,'text'=>$value];
                        }
                        $filters->zip = $zip_arr;
                    }
                }elseif ($filters->filter_type == 'by_specific_vehicle') {
                    if (!empty($filters->mileage)) {
                        $filters->mileage = explode('-', $filters->mileage);
                    }
                    if (!empty($filters->year)) {
                        $filters->year = explode('-', $filters->year);
                    }
                }elseif ($filters->filter_type == 'by_area') {
                    # code...
                }
            }
            $data->ecl_filters = $filters;
        }



        return response()->json(['success'=>$data], $this->successStatus);
    }

    public function campaignsList(){
        $user = Auth::user();
        if (!empty($user)) {
            $campaigns = DB::table('email_campaigns_list')->where('ecl_dealer_id','=',$user->dealer_id)->where('ecl_status','=',1)->get();
            return response()->json(['success'=>$campaigns], $this->successStatus);
        }else{
            return response()->json(['error'=>"Unauthenticate user."], $this->errorStatus);
        }
    }

    public function dtCampaignsList(Request $request){
        $input = $request->all();
        $user = Auth::user();
        //return $input;
        $order_columns = ['ecl_id','ecl_name','ecl_user_cate','ecl_status','ecl_created'];
        $query = DB::table('email_campaigns_list')->where('ecl_dealer_id','=',$user->dealer_id);


        if (!empty($input['search']['value'])) {
            $search_val = "%".$input["search"]["value"]."%";
            $query->where('ecl_name', 'like', $search_val);
            // $query->where(function ($query) use ($search_val) {
                
            //     $query->where('ecl_name', 'like', $search_val)
            //     ->orWhere('leads.l_customer_first_name', 'like', $search_val)
            //     ->orWhere('leads.l_customer_last_name', 'like', $search_val);
            // });
        }
        if (!empty($input['order'][0]['column']) && !empty($order_columns[$input['order'][0]['column']])) {
            $query->orderBy($order_columns[$input['order'][0]['column']],$input['order'][0]['dir']);
        }else{
            $query->orderBy('ecl_id', 'DESC');
        }

        if ($input['start'] >= 0  && $input['length'] >= 0) {
            $query->offset($input['start']);
            $query->limit($input['length']);
        }
        
        $campaigns = $query->get();
        //return response()->json(['success'=>$leads], $this->successStatus); exit;
        $data = [];
        if(!empty($campaigns) && count($campaigns) > 0){
            foreach ($campaigns as $key => $camp) {
                $output = [];
                $output[] = $key+1;
                $output[] = $camp->ecl_name;
                $output[] = $camp->ecl_user_cate;
                if($camp->ecl_status == '1'){
                    $output[] = '<a  class="assin clStatus" id="statusChange'.$camp->ecl_id.'" data-id="'.$camp->ecl_id.'" data-status="'.$camp->ecl_status.'" href="#">Active</a>';
                }else{
                    $output[] = '<a  class="btn-clr-danger clStatus" id="statusChange'.$camp->ecl_id.'" data-id="'.$camp->ecl_id.'" data-status="'.$camp->ecl_status.'" href="#">InActive</a>';
                }
                $output[] = date('m-d-Y H:i A',strtotime($camp->ecl_created));
                $output[] = '<a class="assin" href="edit-campaign-list/'.$camp->ecl_id.'">Edit</a>';
                $data[] = $output;
            }
        }

        $outsfsput = [
            "draw"=>$input['draw'],
            'recordsTotal'=> $this->get_all_campaigns_List_count($user),
            'recordsFiltered'=> $this->get_all_campaigns_List_filter_data_count($user),
            "data"=>$data
        ];
        return response()->json($outsfsput, $this->successStatus);
    }

    public function get_all_campaigns_List_count($user){
        $q = DB::table('email_campaigns_list');
        return $q->count();
    }

    public function get_all_campaigns_List_filter_data_count($user){
        $query = DB::table('email_campaigns_list')->where('ecl_dealer_id','=',$user->dealer_id);
        return $query->count();
    }

    public function addCampaignList(Request $request){
        $validator = Validator::make($request->all(), [ 
            'action' => 'required', 
            'title' => 'required', 
            'user_cate' => 'required',
        ]);
		if ($validator->fails()) { 
            return response()->json(['error'=>$validator->errors()], $this->errorStatus); exit;
        }
        $input = $request->all();
        $user = Auth::user();
        if(!empty($user)){
            $ins_arr = [
                'ecl_dealer_id'=> $user->dealer_id,
                'ecl_name' => $input['title'],
                'ecl_user_cate' => $input['user_cate'],
                //'ecl_created' => date('Y-m-d H:i:s'),
                'ecl_updated' => date('Y-m-d H:i:s')
            ];
            if ($input['action'] == 'add') {
                $ins_arr['ecl_created'] = date('Y-m-d H:i:s');
            }

            $filters=[];
            if ($input['user_cate'] == 'all' || $input['user_cate'] == 'customers') {

                // Customer Created Time
                if (!empty($input['duration'])) {
                    $filters['duration'] = $input['duration'];
                    if ($input['duration'] == 'custom' && !empty($input['customDateFrom']) && !empty($input['customDateTo'])) {
                        $filters['duration_from'] = $input['customDateFrom'];
                        $filters['duration_to'] = $input['customDateTo'];
                    }
                }


                if(!empty($input['filter_type'])){
                    $filters['filter_type'] = $input['filter_type'];

                    if ($input['filter_type'] == 'by_area') {
                        if (!empty($input['city'])) {
                            $filters['city'] = $input['city'];
                        }
                        if (!empty($input['state'])) {
                            $filters['state'] = $input['state'];
                        }
                        //return $input['zip'];
                        if (!empty($input['zip']) && count($input['zip']) > 0) {
                            $zipc = '';
                            foreach ($input['zip'] as $key => $value) {
                                if ($key > 0) {
                                    $zipc .= ',';
                                }
                                $zipc .= $value['id'];
                            }
                            $filters['zip'] = $zipc;
                        }
                    }elseif ($input['filter_type'] == 'by_specific_vehicle') {
                        if (!empty($input['make'])){
                            $filters['make'] = $input['make'];
                        }
                        if (!empty($input['model'])){
                            $filters['model'] = $input['model'];
                        }
                        if (!empty($input['mileage'])){
                            $filters['mileage'] = implode('-',$input['mileage']);
                        }
                        if (!empty($input['year'])){
                            $filters['year'] = implode('-',$input['year']);
                        }
                        if (!empty($input['sales_type'])){
                            $filters['sales_type'] = $input['sales_type'];
                        }
                        if (!empty($input['condition'])){
                            $filters['condition'] = $input['condition'];
                        }
                        if (!empty($input['va_status'])){
                            $filters['va_status'] = $input['va_status'];
                        }

                        if (!empty($input['purchase_duration'])) {
                            $filters['purchase_duration'] = $input['purchase_duration'];
                            if ($input['purchase_duration'] == 'custom' && !empty($input['purchase_date_from']) && !empty($input['purchase_date_to'])) {
                                $filters['purchase_duration_from'] = $input['purchase_date_from'];
                                $filters['purchase_duration_to'] = $input['purchase_date_to'];
                            }
                        }
                    }elseif ($input['filter_type'] == 'by_source') {
                        if (!empty($input['source'])) {
                            $filters['source'] = $input['source'];
                        }
                        if (!empty($input['status'])) {
                            $filters['status'] = $input['status'];
                        }
                        if (!empty($input['agent'])) {
                            $filters['agent'] = $input['agent'];
                        }
                        if (!empty($input['lead_created_duration'])) {
                            $filters['lead_created_duration'] = $input['lead_created_duration'];
                            if ($input['lead_created_duration'] == 'custom' && !empty($input['lead_created_from']) && !empty($input['lead_created_to'])) {
                                $filters['lead_created_from'] = $input['lead_created_from'];
                                $filters['lead_created_to'] = $input['lead_created_to'];
                            }
                        }
                    }
                }
            }

            if (!empty($filters)) {
                $ins_arr['ecl_filters'] = json_encode($filters);
            }

            if ($input['action'] == 'add') {
                $resp = DB::table('email_campaigns_list')->insert($ins_arr);
            }elseif ($input['action'] == 'update') {
                $resp = DB::table('email_campaigns_list')->where('ecl_id','=',$input['id'])->update($ins_arr);
            }else{
                return response()->json(['error'=>"Something went wrong."], $this->errorStatus); exit;
            }

            
            if($resp){
                return response()->json(['success'=>"Successfully added."], $this->successStatus); exit;
            }else{
                return response()->json(['error'=>"Something went wrong."], $this->errorStatus); exit;
            }
        }
    }


    public function getCampaignListUsers(Request $request){
        $validator = Validator::make($request->all(), [ 
            'user_cate' => 'required',
        ]);
        if ($validator->fails()) { 
            return response()->json(['error'=>$validator->errors()], $this->errorStatus); exit;
        }
        $input = $request->all();
        $user = Auth::user();
        if(!empty($user)){
            if ($input['user_cate'] == 'all' || $input['user_cate'] == 'customers') {
                $query = Lead::select('customers.*')
                ->leftJoin('customers', 'leads.l_customer_id', '=', 'customers.c_id')
                ->leftJoin('vehicles', 'leads.l_vehicle_id', '=', 'vehicles.v_id')
                ->where('l_dealer_id','=',$user->dealer_id);
                // Customer Created Time
                if (!empty($input['duration'])) {
                    if ($input['duration'] == 'year'){
                        $lastYear = date("Y-m-d", strtotime("-1 years"));
                        $now = date('Y-m-d');
                        $query->whereBetween('customers.created_at', [$lastYear, $now]);
                    }elseif ($input['duration'] == 'custom' && !empty($input['customDateFrom']) && !empty($input['customDateTo'])) {
                        if($input['customDateFrom'] == $input['customDateTo']){
                            $query->whereDate('customers.created_at', '=', $input['customDateFrom']);
                        }else{
                            $query->whereBetween('customers.created_at', [$input['customDateFrom'], $input['customDateTo']]);
                        }
                    }
                }

                if(!empty($input['filter_type'])){
                    if ($input['filter_type'] == 'by_area') {
                        if (!empty($input['city'])) {
                            $query->where('c_city', 'like', '%'.$input['city'].'%');
                        }
                        if (!empty($input['state'])) {
                            $query->where('c_state', 'like', '%'.$input['state'].'%');
                        }
                        if (!empty($input['zip'])) {
                            $query->where(function($query) use ($input){
                                foreach ($input['zip'] as $key => $value) {
                                    if ($key == 0) {
                                        $query->where('c_zip','LIKE','%'.$value['id'].'%');
                                    }else{
                                        $query->orWhere('c_zip','LIKE','%'.$value['id'].'%');
                                    }
                                }
                            });
                        }
                    }elseif ($input['filter_type'] == 'by_specific_vehicle') {
                        if (!empty($input['make'])){
                            $query->where('v_make', 'like', '%'.$input['make'].'%');
                        }
                        if (!empty($input['model'])){
                            $query->where('v_model', 'like', '%'.$input['model'].'%');
                        }
                        if (!empty($input['mileage'])){
                            $query->where('v_mileage', '>=',$input['mileage'][0]);
                            $query->where('v_mileage', '<=',$input['mileage'][1]);
                        }
                        if (!empty($input['year'])){
                            $query->where('v_year', '>=',$input['year'][0]);
                            $query->where('v_year', '<=',$input['year'][1]);
                        }
                        // if (!empty($input['sales_type'])){
                        //     $filters['sales_type'] = $input['sales_type'];
                        // }
                        if (!empty($input['condition'])){
                            if ($input['condition'] == 'used') {
                                $query->where('v_new_used_status', '=','Used');
                            }else{
                                $query->whereNull('v_new_used_status');
                            }
                        }
                        if (!empty($input['va_status'])){
                            if ($input['va_status'] == 'sold') {
                                $query->where('l_status', '=',6);
                                if (!empty($input['purchase_duration'])) {
                                    if ($input['purchase_duration'] == 'year'){
                                        $lastYear = date("Y-m-d", strtotime("-1 years"));
                                        $now = date('Y-m-d');
                                        $query->whereBetween('l_updated', [$lastYear, $now]);
                                    }elseif ($input['purchase_duration'] == 'custom' && !empty($input['purchase_date_from']) && !empty($input['purchase_date_to'])) {
                                        if($input['purchase_date_from'] == $input['purchase_date_to']){
                                            $query->whereDate('l_updated', '=', $input['purchase_date_from']);
                                        }else{
                                            $query->whereBetween('l_updated', [$input['purchase_date_from'], $input['purchase_date_to']]);
                                        }
                                    }
                                }
                            }else{
                                $query->where('l_status', '!=',6);
                            }
                        }
                    }elseif ($input['filter_type'] == 'by_source') {
                        if (!empty($input['source'])) {
                            $query->where('l_source', '=',$input['source']);
                        }
                        if (!empty($input['status'])) {
                            $query->where('l_status', '=',$input['status']);
                        }
                        if (!empty($input['agent'])) {
                            $query->whereExists(function ($query) use ($input){
                                $query->select(DB::raw(1))->from('leads_access')
                                ->whereRaw('la_lead_id = leads.l_id AND la_user_id ='.$input['agent']);
                            });
                        }

                        if (!empty($input['lead_created_duration'])) {
                            if ($input['lead_created_duration'] == 'year'){
                                $lastYear = date("Y-m-d", strtotime("-1 years"));
                                $now = date('Y-m-d');
                                $query->whereBetween('l_created', [$lastYear, $now]);
                            }elseif ($input['lead_created_duration'] == 'custom' && !empty($input['lead_created_from']) && !empty($input['lead_created_to'])) {
                                if($input['lead_created_from'] == $input['lead_created_to']){
                                    $query->whereDate('l_created', '=', $input['lead_created_from']);
                                }else{
                                    $query->whereBetween('l_created', [$input['lead_created_from'], $input['lead_created_to']]);
                                }
                            }
                        }
                    }
                }

                $data = $query->groupBy('c_id')->get();
                //$resp = $this->unique_multidimensional_array($data,'c_id');
                return response()->json(['success'=>$data], $this->successStatus);
            }elseif ($user_cate == 'employees') {
                $data = User::select('name','email')->where('dealer_id','=',$user->dealer_id)
                ->where('parent_id','=',$user->id)->where('user_type','=',2)->where('user_status','=',1)->get();
                return response()->json(['success'=>$data], $this->successStatus);
            }else{
                return response()->json(['success'=>[]], $this->successStatus);
            }
        }else{
            return response()->json(['error'=>"Something went wrong."], $this->errorStatus);
        }
    }





    public function changeCampaignListStatus(Request $request){
        $validator = Validator::make($request->all(), [ 
            'id' => 'required', 
            'status' => 'required',
        ]);
		if ($validator->fails()) { 
            return response()->json(['error'=>$validator->errors()], $this->errorStatus); exit;
        }
        $input = $request->all();
        $user = Auth::user();
        if($input['status'] == '0'){
            $status = 1;
        }elseif($input['status'] == '1'){
            $status = 0;
        }
        if(!empty($user)){
            $upd_arr = [
                'ecl_status' => $status,
                'ecl_updated' => date('Y-m-d H:i:s')
            ];
            $resp = DB::table('email_campaigns_list')->where('ecl_id','=',$input['id'])
            ->where('ecl_dealer_id','=',$user->dealer_id)->update($upd_arr);
            if($resp){
                return response()->json(['success'=>"Successfully update."], $this->successStatus); exit;
            }else{
                return response()->json(['error'=>"Something went wrong."], $this->errorStatus); exit;
            }
        }
    }

    // Add New Campaign Start

    public function addNewCampaign(Request $request){
        $validator = Validator::make($request->all(), [ 
            'campaign_name' => 'required', 
            'subject' => 'required',
            'from_name' => 'required',
            'from_email' => 'required',
            'template' => 'required',
            'campaign_list' => 'required'
        ]);
		if ($validator->fails()) { 
            return response()->json(['error'=>$validator->errors()], $this->errorStatus); exit;
        }
        $input = $request->all();
        $user = Auth::user();
        if(!empty($user)){
            $ins_arr = [
                'ec_name'=> $input['campaign_name'],
                'ec_subject' => $input['subject'],
                'ec_from_name' => $input['from_name'],
                'ec_from_email' => $input['from_email'],
                'ec_template' => $input['template'],
                'ec_template_design' => json_encode($input['design']),
                'ec_dealer_id' => $user->dealer_id,
                'ec_created' => date('Y-m-d H:i:s'),
                'ec_updated' => date('Y-m-d H:i:s')
            ];
            if ($input['same_reply_to'] == true) {
                $ins_arr['ec_replyTo_name'] = $input['from_name'];
                $ins_arr['ec_replyTo_email'] = $input['from_email'];
            }elseif ($input['same_reply_to'] == false) {
                $ins_arr['ec_replyTo_name'] = $input['replyTo_name'];
                $ins_arr['ec_replyTo_email'] = $input['replyTo_email'];
            }

            if ($input['schedule'] == true) {
                $date = date('Y-m-d',strtotime($input['date'])).' '.$input['time'];
                $ins_arr['ec_date'] = date('Y-m-d H:i:s',strtotime($date));
            }elseif ($input['schedule'] == false) {
                $addingFiveMinutes= strtotime("+5 minutes");
                $ins_arr['ec_date'] =  date('Y-m-d H:i:s', $addingFiveMinutes);
            }
            $campaigns_ids = '';
            if(!empty($input['campaign_list'])){
                if (count($input['campaign_list']) > 0) {
                    foreach ($input['campaign_list'] as $key => $value) {
                        if($key == 0){
                            $campaigns_ids .= $value['ecl_id'];
                        }else{
                            $campaigns_ids .= ','.$value['ecl_id'];
                        }
                    }
                }
            }
            $ins_arr['ec_campaign_id'] = $campaigns_ids;

            //return $ins_arr;
            $resp = DB::table('email_campaigns')->insertGetId($ins_arr);
            if($resp){
                $res_send = $this->sendCampaigns($resp);
                return response()->json(['success'=>"Successfully added.",'data'=>$res_send], $this->successStatus); exit;
            }else{
                return response()->json(['error'=>"Something went wrong."], $this->errorStatus); exit;
            }
        }
    }

    public function campaigns(){
        $user = Auth::user();
        if (!empty($user)) {
            $campaigns = DB::table('email_campaigns')->where('ec_dealer_id','=',$user->dealer_id)->where('ec_status','=',1)->get();
            return response()->json(['success'=>$campaigns], $this->successStatus);
        }else{
            return response()->json(['error'=>"Unauthenticate user."], $this->errorStatus);
        }
    }






    // Send Campaigns


    public function sendCampaigns($cam_id){
        $campaign = DB::table('email_campaigns')
        ->leftJoin('email_campaigns_list', 'email_campaigns.ec_campaign_id', '=', 'email_campaigns_list.ecl_id')
        ->leftJoin('dealers', 'dealers.dl_id', '=', 'email_campaigns.ec_dealer_id')
        ->where('ec_id',$cam_id)->where('ec_status','=',1)->first();
        //->whereDate('ec_date', Carbon::today())->where('ec_status','=',1)->first();


        if (!empty($campaign)) {
            // Get Campaign Users
            $contscts = $this->getUsersForCamoaign($campaign->ecl_dealer_id,$campaign->ecl_user_cate,$campaign->ecl_filters);
            //return response()->json($contscts);
            if (!empty($contscts)) {  
                $apiKey = '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';
                //$apiKey = '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';
                //$apiKey = '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';
                $MainUrlEndPoint = 'https://api.sendgrid.com/v3/marketing/';
                $list_endpoint = 'lists';
                $contacts_endpoint = 'contacts';
                $campaign_endpoint = 'singlesends';
                $senders_endpoint = 'senders';




                //return $sender = $this->SendGrid($apiKey, $MainUrlEndPoint, $senders_endpoint, 'GET');


                //Create List
                $method = 'POST';
                $postData = array(
                    'name' => $campaign->ec_name.' conatct list '.uniqid()
                );
                $campaignList = $this->SendGrid($apiKey, $MainUrlEndPoint, $list_endpoint, $method, $postData);

                if (!empty($campaignList->id)) {
                    // Add Contacts
                    $method = 'PUT';
                    foreach ($contscts as $key => $usre) {
                        if (!empty($usre->email) && !empty($usre->name)) {
                            $conatctPostData = array(
                                'list_ids' => array($campaignList->id),
                                "contacts" => [array(
                                    "email" => $usre->email,
                                    "first_name" => $usre->name,
                                    //"last_name" =>"vvv",
                                    // "address_line_1" => "string (optional)",
                                    // "address_line_2" => "string (optional)",
                                    // "alternate_emails" => array(
                                    //     "email_1 (optional)",
                                    //     "email_2 (optional)"
                                    // ),
                                    // "city" => "string (optional)",
                                    // "country" => "string (optional)",
                                    // "postal_code" => "string (optional)",
                                    // "state_province_region" => "string (optional)"
                                )]
                            );
                            $resp = $this->SendGrid($apiKey, $MainUrlEndPoint, $contacts_endpoint, $method,$conatctPostData);
                        }
                    }


                    if (!empty($resp->job_id)) {

                        // Add Sender
                        // $method = 'POST';
                        // $postData = array(
                        //     "nickname" => $campaign->dl_name.'_'.uniqid(),
                        //     "from" => array('email' => $campaign->ec_from_email,'name' => $campaign->ec_from_name ),
                        //     "reply_to" => array('email' => $campaign->ec_replyTo_email,'name' => $campaign->ec_replyTo_name ),
                        //     "address" => "Charlotte, NC",
                        //     "city" => "Charlotte",
                        //     "country" => "USA"
                        // );
                        // $sender = $this->SendGrid($apiKey, $MainUrlEndPoint, $senders_endpoint, $method, $postData);

                        // ADD Campaign 
                        //$cDate = date('Y-m-d H:i:s');
                        //$date = date('Y-m-d H:i:s', strtotime($cDate. ' + 5 minutes'));
                        //if (!empty($sender)) {
                        	$gmDate = str_replace('+00:00', 'Z', gmdate('c', strtotime($campaign->ec_date)));
	                        $method = 'POST';
	                        $postData = array(
	                            "name" => $campaign->ec_name,
	                            "send_at" => $gmDate, //UTC-TZ-Format (optional)
	                            "send_to" => array(
	                                "list_ids" => array($campaignList->id),
	                                "all" => false //(TRUE) if all lists
	                            ),
	                            "email_config" => array(
	                                "subject" => $campaign->ec_subject,
	                                "html_content" => $campaign->ec_template,
	                                "plain_content" => "",
	                                "generate_plain_content" => true,
	                                "custom_unsubscribe_url" => "https://webmatech.com/unautorize",
	                                "sender_id" => 1520432 //$sender->id //integer
	                            )
	                        );
	                        $resp = $this->SendGrid($apiKey, $MainUrlEndPoint, $campaign_endpoint, $method, $postData);
                            if (!empty($resp->id)) {
                                if ($resp->status == 'draft') {
                                    $schedule_endpoint = $campaign_endpoint.'/'.$resp->id.'/schedule';
                                    $sData = array(
                                        "send_at" => $gmDate, //UTC-TZ-Format (optional)
                                    );
                                    $resp = $this->SendGrid($apiKey, $MainUrlEndPoint, $schedule_endpoint,'PUT', $sData);
                                }
                            }
	                        return response()->json(['success'=>$resp], $this->successStatus);
                        //}
                    }
                }else{
                    return response()->json(['error'=>"Campaign list does not created."], $this->errorStatus); exit;
                }
            }
        }
    }

    
    public function getUsersForCamoaign($dealer,$user_cate='',$filter=''){
        if ($user_cate == 'all' || $user_cate == 'customers') {
            $input = json_decode($filter);
            $query = Lead::select('c_first_name as name','c_last_name as last_name','c_email as email')
            ->leftJoin('customers', 'leads.l_customer_id', '=', 'customers.c_id')
            ->leftJoin('vehicles', 'leads.l_vehicle_id', '=', 'vehicles.v_id')
            ->where('l_dealer_id','=',$dealer);

            if (!empty($input)) {
                if (!empty($input->duration)) {
                    if ($input->duration == 'year'){
                        $lastYear = date("Y-m-d", strtotime("-1 years"));
                        $now = date('Y-m-d');
                        $query->whereBetween('customers.created_at', [$lastYear, $now]);
                    }elseif ($input->duration == 'custom' && !empty($input->duration_from) && !empty($input->duration_to)) {
                        if($input->duration_from == $input->duration_to){
                            $query->whereDate('customers.created_at', '=', $input->duration_from);
                        }else{
                            $query->whereBetween('customers.created_at', [$input->duration_from, $input->duration_to]);
                        }
                    }
                }

                if(!empty($input->filter_type)){
                    if ($input->filter_type == 'by_area') {
                        if (!empty($input->city)) {
                            $query->where('c_city', 'like', '%'.$input->city.'%');
                        }
                        if (!empty($input->state)) {
                            $query->where('c_state', 'like', '%'.$input->state.'%');
                        }
                        if (!empty($input->zip)) {
                            $query->where(function($query) use ($input){
                                $zips = explode(",",$input->zip);
                                foreach ($zips as $key => $value) {
                                    if ($key == 0) {
                                        $query->where('c_zip','LIKE','%'.$value['id'].'%');
                                    }else{
                                        $query->orWhere('c_zip','LIKE','%'.$value['id'].'%');
                                    }
                                }
                            });
                        }
                    }elseif ($input->filter_type == 'by_specific_vehicle') {
                        if (!empty($input->make)){
                            $query->where('v_make', 'like', '%'.$input->make.'%');
                        }
                        if (!empty($input->model)){
                            $query->where('v_model', 'like', '%'.$input->model.'%');
                        }
                        if (!empty($input->mileage)){
                            $mileages = explode("-",$input->mileage);
                            $query->where('v_mileage', '>=',$mileages[0]);
                            $query->where('v_mileage', '<=',$mileages[1]);
                        }
                        if (!empty($input->year)){
                            $years = explode("-",$input->year);
                            $query->where('v_year', '>=',$years[0]);
                            $query->where('v_year', '<=',$years[1]);
                        }
                        // if (!empty($input['sales_type'])){
                        //     $filters['sales_type'] = $input['sales_type'];
                        // }
                        if (!empty($input->condition)){
                            if ($input->condition == 'used') {
                                $query->where('v_new_used_status', '=','Used');
                            }else{
                                $query->whereNull('v_new_used_status');
                            }
                        }
                        if (!empty($input->va_status)){
                            if ($input->va_status == 'sold') {
                                $query->where('l_status', '=',6);

                                if (!empty($input->purchase_duration)) {
                                    if ($input->purchase_duration == 'year'){
                                        $lastYear = date("Y-m-d", strtotime("-1 years"));
                                        $now = date('Y-m-d');
                                        $query->whereBetween('l_updated', [$lastYear, $now]);
                                    }elseif ($input->purchase_duration == 'custom' && !empty($input->purchase_duration_from) && !empty($input->purchase_duration_to)) {
                                        if($input->purchase_duration_from == $input->purchase_duration_to){
                                            $query->whereDate('l_updated', '=',$input->purchase_duration_from);
                                        }else{
                                            $query->whereBetween('l_updated', [$input->purchase_duration_from, $input->purchase_duration_to]);
                                        }
                                    }
                                }

                            }else{
                                $query->where('l_status', '!=',6);
                            }
                        }
                    }elseif ($input->filter_type == 'by_source') {
                        if (!empty($input->source)) {
                            $query->where('l_source', '=',$input->source);
                        }
                        if (!empty($input->status)) {
                            $query->where('l_status', '=',$input->status);
                        }
                        if (!empty($input->agent)) {
                            $query->whereExists(function ($query) use ($input){
                                $query->select(DB::raw(1))->from('leads_access')
                                ->whereRaw('la_lead_id = leads.l_id AND la_user_id ='.$input->agent);
                            });
                        }

                        if (!empty($input->lead_created_duration)) {
                            if ($input->lead_created_duration == 'year'){
                                $lastYear = date("Y-m-d", strtotime("-1 years"));
                                $now = date('Y-m-d');
                                $query->whereBetween('l_created', [$lastYear, $now]);
                            }elseif ($input->lead_created_duration == 'custom' && !empty($input->lead_created_from) && !empty($input->lead_created_to)) {
                                if($input->lead_created_from == $input->lead_created_to){
                                    $query->whereDate('l_created', '=', $input->lead_created_from);
                                }else{
                                    $query->whereBetween('l_created', [$input->lead_created_from,$input->lead_created_to]);
                                }
                            }
                        }
                    }
                }

                return $data = $query->groupBy('c_id')->get();
            }else{
                return [];
            }
        }else{
            return [];
        }
    }



    public function getUsersForCamoaign_old($dealer,$user_cate='',$filter=''){
        if ($user_cate == 'all' || $user_cate == 'customers') {
            $filters = json_decode($filter);
            $query = Lead::select('c_first_name as name','c_last_name as last_name','c_email as email')
            ->leftJoin('customers', 'leads.l_customer_id', '=', 'customers.c_id')
            ->leftJoin('vehicles', 'leads.l_vehicle_id', '=', 'vehicles.v_id')
            ->where('l_dealer_id','=',$dealer);
            
            // $query = DB::table('lead_details')->select('l_customer_first_name as name','l_customer_last_name as last_name','l_customer_email as email')
            // ->leftJoin('leads', 'leads.l_id', '=', 'lead_details.ld_lead_id')
            // ->where('l_dealer_id','=',$dealer);
            if (!empty($filters)) {

                if ($filters->by == 'by_specific_vehicle') {
                    if (!empty($filters->model)) {
                        $query->where('v_model','=',$filters->model);
                    }
                    if (!empty($filters->saleType)) {
                        $query->where('l_status','=',$filters->saleType);
                    }
                    if (!empty($filters->timePeriod)) {
                        if ($filters->timePeriod == 'threeYago') {
                            $cDate = date('Y-m-d');
                            $date = date('Y-m-d', strtotime($cDate. ' - 3 year'));
                            $query->whereDate('l_created','>',$date);
                            $query->where('l_status', '=', '6');
                            // $query->where(function ($query) {
                            //     $query->where('ld_status', '=', '5')
                            //     ->orWhere('ld_status', '=', '6');
                            // });

                        }elseif ($filters->timePeriod == 'specific_time_period') {
                            $query->whereDate('l_created','>=',$filters->start);
                            $query->whereDate('l_created','<=',$filters->end);
                        }
                    }
                }elseif($filters->by == "by_source"){
                    // $query->whereExists(function ($q) use ($filters){
                    //     $q->select(DB::raw(1))
                    //           ->from('leads')
                    //           ->whereRaw('l_customer_id = c_id AND l_source = '.$filters->source);
                    // });
                    $query->where('l_source','=',$filters->source);
                }elseif($filters->by == "by_area"){

                }
            }
            $data = $query->get();

            return $this->unique_multidimensional_array($data,'email');

            //return array_unique($data);
        }elseif ($user_cate == 'employees') {
            return $data = User::select('name','email')->where('parent_id','=',$dealer)->where('user_status','=',1)->get();
        }elseif ($user_cate == 'dealers') {
            return $data = DB::table('dealers')->select('dl_name as name','dl_email as email')->where('dl_status','=',1)->get();
        }else{
            return [];
        }
    }

    public function SendGrid($apiKey = NULL, $MainUrlEndPoint = NULL, $endpoint = NULL, $Method = NULL, $data = NULL){
        $postData = NULL;
        if($data != NULL){
            $postData = json_encode($data);
        }
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $MainUrlEndPoint.$endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => $Method,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_HTTPHEADER => array(
                "Authorization: Bearer ".$apiKey,
                "Content-Type: application/json"
            ),
        ));

        $response = curl_exec($curl);
        $err = curl_error($curl);

        curl_close($curl);

        if ($err) {
            return "cURL Error #:" . $err;
        } else {
            return json_decode($response);
        }
    }

    public function unique_multidimensional_array($array, $key) {
        $temp_array = [];
        $i = 0;
        $key_array = array();

        foreach($array as $val) {
            if (!in_array($val->email, $key_array)) {
                $key_array[$i] = $val->email;
                $temp_array[] = $val;
            }
            $i++;
        }
        return $temp_array;
    }

    public function sg_createContactsList(){

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL,"https://api.sendgrid.com/v3/contactdb/lists");
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS,http_build_query(array('name' => 'TestList')));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Authorization: Bearer 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',
            'Content-Type: application/json'
        ));


        // receive server response ...
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $server_output = curl_exec ($ch);
        curl_close ($ch);
        
        //$data = json_decode($server_output);
        return $server_output;
    }







    // Email Templates
    public function dt_templates_list(Request $request){
        $input = $request->all();
        $user = Auth::user();
        //return $input;
        $order_columns = ['ut_id','ut_title','ut_type','ut_status'];
        $query = UserTemplate::where('ut_dealer_id','=',$user->dealer_id);
        if ($user->user_type == 2) {
            $query->where('ut_user_id','=', $user->id);
        }

        if (!empty($input['search']['value'])) {
            $search_val = "%".$input["search"]["value"]."%";
            $query->where('ut_title', 'like', $search_val);
        }
        if (!empty($input['order'][0]['column']) && !empty($order_columns[$input['order'][0]['column']])) {
            $query->orderBy($order_columns[$input['order'][0]['column']],$input['order'][0]['dir']);
        }else{
            $query->orderBy('ut_id', 'DESC');
        }

        if ($input['start'] >= 0  && $input['length'] >= 0) {
            $query->offset($input['start']);
            $query->limit($input['length']);
        }
        
        $templates = $query->get();
        //return response()->json(['success'=>$leads], $this->successStatus); exit;
        $data = [];
        if(!empty($templates) && count($templates) > 0){
            foreach ($templates as $key => $temp) {
                $output = [];
                $output[] = $key+1;
                $output[] = $temp->ut_title;
                // if($camp->ut_type == '1'){
                //     $output[] = 'Email';
                // }else{
                //     $output[] = 'Campaigns';
                // }
                if($temp->ut_status == '1'){
                    $output[] = '<a  class="assin clStatus" id="statusChange'.$temp->ut_id.'" data-id="'.$temp->ut_id.'" data-status="'.$temp->ut_status.'" href="#">Active</a>';
                }else{
                    $output[] = '<a  class="btn-clr-danger clStatus" id="statusChange'.$temp->ut_id.'" data-id="'.$temp->ut_id.'" data-status="'.$camp->ut_status.'" href="#">InActive</a>';
                }
                $output[] = date('m-d-Y h:i A',strtotime($temp->created_on));
                $data[] = $output;
            }
        }

        $outsfsput = [
            "draw"=>$input['draw'],
            'recordsTotal'=> $this->get_all_templates_List_count($user),
            'recordsFiltered'=> count($data),
            "data"=>$data
        ];
        return response()->json($outsfsput, $this->successStatus);
    }

    public function get_all_templates_List_count($user){
        $q = UserTemplate::where('ut_dealer_id','=',$user->dealer_id);
        if ($user->user_type == 2) {
            $q->where('ut_user_id','=', $user->id);
        }
        return $q->count();
    }

    public function addTemplate(Request $request){
        $validator = Validator::make($request->all(), [ 
            'title' => 'required', 
            'body' => 'required',
        ]);
		if ($validator->fails()) { 
            return response()->json(['error'=>$validator->errors()], $this->errorStatus); exit;
        }
        $input = $request->all();
        $user = Auth::user();

        if(!empty($user)){
            $ins_arr = [
                'ut_user_id'=> $user->id,
                'ut_dealer_id' => $user->dealer_id,
                'ut_title' => $input['title'],
                'ut_content' => $input['body'],
                'created_on' => date('Y-m-d H:i:s'),
                'updated_on' => date('Y-m-d H:i:s')
            ];

            $resp = UserTemplate::insert($ins_arr);
            if($resp){
                return response()->json(['success'=>"Successfully added."], $this->successStatus); exit;
            }else{
                return response()->json(['error'=>"Something went wrong."], $this->errorStatus); exit;
            }
        }else{
            return response()->json(['error'=>"not-login"], $this->errorStatus); exit;
        }
    }

    public function getTemplates($type='',$status=''){
        $user = Auth::user();
        $query = UserTemplate::where('ut_dealer_id','=',$user->dealer_id)->where('ut_user_id','=',$user->id)->where('ut_status','=',1);
        if(!empty($type)){
            $query->where('ut_type','=',$type);
        }
        // if($status != ''){
        //     $query->where('ut_status','=',$status);
        // }
        $templates = $query->get();
        return response()->json(['success'=>$templates], $this->successStatus);
    }

    public function updateTemplate(Request $request){
        $validator = Validator::make($request->all(), [ 
            'ut_id' => 'required', 
            'ut_content' => 'required',
        ]);
		if ($validator->fails()) { 
            return response()->json(['error'=>$validator->errors()], $this->errorStatus); exit;
        }
        $input = $request->all();
        $user = Auth::user();

        if(!empty($user)){
            $upd_arr = [
                'ut_content' => $input['ut_content'],
                'updated_on' => date('Y-m-d H:i:s')
            ];

            $resp = UserTemplate::where('ut_id','=',$input['ut_id'])->update($upd_arr);
            if($resp){
                return response()->json(['success'=>"Successfully updates."], $this->successStatus);
            }else{
                return response()->json(['error'=>"Something went wrong."], $this->errorStatus);
            }
        }else{
            return response()->json(['error'=>"not-login"], $this->errorStatus);
        }
    }
    
    public function deleteTemplate(Request $request){
        $validator = Validator::make($request->all(), [ 
            'ut_id' => 'required'
        ]);
		if ($validator->fails()) { 
            return response()->json(['error'=>$validator->errors()], $this->errorStatus); exit;
        }
        $input = $request->all();
        $user = Auth::user();

        if(!empty($user)){
            $resp = UserTemplate::where('ut_id','=',$input['ut_id'])->delete();
            if($resp){
                return response()->json(['success'=>"Deleted successfully."], $this->successStatus);
            }else{
                return response()->json(['error'=>"Something went wrong."], $this->errorStatus);
            }
        }else{
            return response()->json(['error'=>"not-login"], $this->errorStatus);
        }
    }
}
