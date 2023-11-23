<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Traits\ApiResponser;
use App\Helpers\Helper;

class DashboardController extends Controller
{
    use ApiResponser;
    public $successStatus = 200;
    public $errorStatus = 401;


    public function saleStats(Request $request)
    {
        $input = $request->all();
        $user_id = Auth::id();
        $business = Helper::getBusinessByUserId($user_id);
        
        $query = DB::table("transactions")
        ->select(
            DB::raw('SUM(total_price) as gross_sale'),
            DB::raw('SUM((total_price - transaction_fee)) as net_sale'),
            DB::raw('SUM(CASE WHEN transaction_type = "Booking" THEN total_price END) as booking_sale'),
            DB::raw('SUM(CASE WHEN transaction_type = "Queue" THEN total_price Else 0 END) as queue_sale')
        );

        $query->leftJoin('bookings', 'transactions.booking_id', '=', 'bookings.id');

        
        if($input['duration'] == 'monthly'){
            $query->whereDate('date', '>', date('Y-m-d',strtotime('-30 days')));
        }elseif ($input['duration'] == 'today') {
            $query->whereDate('date', '=', date('Y-m-d'));
        }elseif ($input['duration'] == 'custom') {
            if($input['from'] == $input['to']){
                $query->whereDate('date', '=', $input['from']);
            }else{
                $query->whereBetween('date', [$input['from'], $input['to']]);
            }
        }

        //Employe base    
        if (!empty($input['id'])) {
            $query->where("bookings.provider_id",$input['id']);
        }

        $query->where("transactions.business_id",$business->id);


        $salesCounter = $query->first();


        $query_2 = DB::table("transactions")
        ->select(
            DB::raw('SUM(total_price) as gross_sale'),
            DB::raw('SUM((total_price - transaction_fee)) as net_sale'),
            DB::raw('SUM(CASE WHEN transaction_type = "Booking" THEN total_price END) as booking_sale'),
            DB::raw('SUM(CASE WHEN transaction_type = "Queue" THEN total_price Else 0 END) as queue_sale')
        );
        $query_2->leftJoin('bookings', 'transactions.booking_id', '=', 'bookings.id');

        if($input['duration'] == 'monthly'){
            $previousmonth = date('Y-m-d',strtotime('-60 days'));
            $query_2->whereBetween('date', [ $previousmonth, date('Y-m-d',strtotime('-30 days'))]);
        }elseif ($input['duration'] == 'today') {
            $query_2->whereDate('date', '=', date('Y-m-d',strtotime('-1 days')));
        }elseif ($input['duration'] == 'custom') {
            if(!empty($input['from']) && !empty($input['to'])){
                $date1=date_create($input['from']);
                $date2=date_create($input['to']);
                $diff=date_diff($date1,$date2);
                $previousCustomDateDiff = date('Y-m-d',strtotime('-'.$diff->days));
               $query_2->whereBetween('date', [$previousCustomDateDiff, $input['from']]);
            }
        }

        //Employe base    
        if (!empty($input['id'])) {
            $query_2->where("bookings.provider_id",$input['id']);
        }

        $query_2->where("transactions.business_id",$business->id);


        $inflationCounter = $query_2->first();



        $counter = [
            'gross' =>  !empty($salesCounter->gross_sale)? $salesCounter->gross_sale:"0",
            'net' => !empty($salesCounter->net_sale)? $salesCounter->net_sale:"0",
            'appt' => !empty($salesCounter->booking_sale)? $salesCounter->booking_sale:"0",
            'queue' => !empty($salesCounter->queue_sale)? $salesCounter->queue_sale:"0",
            'grossPre' =>  !empty($inflationCounter->gross_sale)? $inflationCounter->gross_sale:"0",
            'netPre' => !empty($inflationCounter->net_sale)? $inflationCounter->net_sale:"0",
            'apptPre' => !empty($inflationCounter->booking_sale)? $inflationCounter->booking_sale:"0",
            'queuePre' => !empty($inflationCounter->queue_sale)? $inflationCounter->queue_sale:"0"
            
        ];
        $counter['grossPercent'] =  $this->checkSaleDiff($counter['gross'],$counter['grossPre']);
        $counter['netPercent'] = $this->checkSaleDiff($counter['net'],$counter['netPre']);
        $counter['apptPercent'] = $this->checkSaleDiff($counter['appt'],$counter['apptPre']);
        $counter['queuePercent'] = $this->checkSaleDiff($counter['queue'],$counter['queuePre']);
        return $this->success($counter);
         
    }
    public function bookingStats(Request $request)
    {
        $input = $request->all();
        $user_id = Auth::id();
        $business = Helper::getBusinessByUserId($user_id);
        
        $query = DB::table("bookings")
        ->select(
            DB::raw('count(CASE WHEN booking_type = "1" THEN 1 END) as total_queue'),
            DB::raw('count(CASE WHEN booking_type = "1" AND (booking_status = "0" OR booking_status = "1"  ) THEN 1 END) as pending_queue'),
            DB::raw('count(CASE WHEN booking_type = "1" AND booking_status = "3" THEN 1 END) as comp_queue'),
            DB::raw('count(CASE WHEN booking_type = "1" AND booking_status = "2" THEN 1 END) as cancel_queue'),
            DB::raw('count(CASE WHEN booking_type = "2" THEN 1 END) as total_appt'),
            DB::raw('count(CASE WHEN booking_type = "2" AND (booking_status = "0" OR booking_status = "1"  ) THEN 1 END) as pending_appt'),
            DB::raw('count(CASE WHEN booking_type = "2" AND booking_status = "3" THEN 1 END) as comp_appt'),
            DB::raw('count(CASE WHEN booking_type = "2" AND booking_status = "2" THEN 1 END) as cancel_appt')
           
        );

        
        if($input['duration'] == 'monthly'){
            $query->whereDate('booking_date', '>', date('Y-m-d',strtotime('-30 days')));
        }elseif ($input['duration'] == 'today') {
            $query->whereDate('booking_date', '=', date('Y-m-d'));
        }elseif ($input['duration'] == 'custom') {
            if($input['from'] == $input['to']){
                $query->whereDate('booking_date', '=', $input['from']);
            }else{
                $query->whereBetween('booking_date', [$input['from'], $input['to']]);
            }
        }

        //Employe base    
        if (!empty($input['id'])) {
            $query->where("provider_id",$input['id']);
        }


        $query->where("business_id",$business->id);


        $bookingCounter = $query->first();
        $counter = [
            'total_queues' =>  !empty($bookingCounter->total_queue)? $bookingCounter->total_queue:"0",
            'pending_queue' => !empty($bookingCounter->pending_queue)? $bookingCounter->pending_queue:"0",
            'comp_queues' => !empty($bookingCounter->comp_queue)? $bookingCounter->comp_queue:"0",
            'cancel_queues' => !empty($bookingCounter->cancel_queue)? $bookingCounter->cancel_queue:"0",
            'total_appts' =>  !empty($bookingCounter->total_appt)? $bookingCounter->total_appt:"0",
            'pending_appt' => !empty($bookingCounter->pending_appt)? $bookingCounter->pending_appt:"0",
            'comp_appts' => !empty($bookingCounter->comp_appt)? $bookingCounter->comp_appt:"0",
            'cancel_appts' => !empty($bookingCounter->cancel_appt)? $bookingCounter->cancel_appt:"0"
        ];
        return $this->success($counter);
         
    }
    public function apptCounters(Request $request)
    {
        $input = $request->all();
        $user_id = Auth::id();
        $business = Helper::getBusinessByUserId($user_id);
        
        $query = DB::table("bookings")
        ->select(
            DB::raw('count(CASE WHEN booking_type = "2" THEN 1 END) as total_appt'),
            DB::raw('count(CASE WHEN booking_type = "2" AND booking_status = "3" THEN 1 END) as comp_appt'),
            DB::raw('count(CASE WHEN booking_type = "2" AND (booking_status = "0" OR booking_status = "1"  ) THEN 1 END) as pending_appt'),
            DB::raw('count(CASE WHEN booking_type = "2" AND booking_status = "2" THEN 1 END) as comp_appt'),
            DB::raw('count(CASE WHEN booking_type = "2" AND booking_status = "4" THEN 1 END) as no_show_appt')
           
        );

        
        if($input['duration'] == 'monthly'){
            $query->whereDate('booking_date', '>', date('Y-m-d',strtotime('-30 days')));
        }elseif ($input['duration'] == 'today') {
            $query->whereDate('booking_date', '=', date('Y-m-d'));
        }elseif ($input['duration'] == 'custom') {
            if($input['from'] == $input['to']){
                $query->whereDate('booking_date', '=', $input['from']);
            }else{
                $query->whereBetween('booking_date', [$input['from'], $input['to']]);
            }
        }

        //Employe base    
        if (!empty($input['id'])) {
            $query->where("provider_id",$input['id']);
        }

        $query->where("business_id",$business->id);


        $bookingCounter = $query->first();
        
        //$counter = [$created,0,0,0,$visits];
        $counter = [
            0 =>  !empty($bookingCounter->total_appt)? $bookingCounter->total_appt:0,
            1 => !empty($bookingCounter->comp_appt)? $bookingCounter->comp_appt:0,
            2 => !empty($bookingCounter->pending_appt)? $bookingCounter->pending_appt:0,
            3 => !empty($bookingCounter->comp_appt)? $bookingCounter->comp_appt:0,
            4 =>  !empty($bookingCounter->no_show_appt)? $bookingCounter->no_show_appt:0,
        ];
        // $labels = ['Created','Scheduled','Confirmed','Missed','Shown'];
        // $colors = ['#793BE3','#47ceff','#19BE93','#ff470e','#FFCB46'];

        $data["counter"][0] = ['data'=>$counter];
        // ["counter"=>$counter,'labels'=>$labels, "colors"=>$colors]


        return $this->success($data);
         
    }

    public function sourceQueues(Request $request)
    {
        $input = $request->all();
        $user_id = Auth::id();
        $business = Helper::getBusinessByUserId($user_id);
        
        $query = DB::table("bookings")
        ->select(
            DB::raw('count(CASE WHEN booking_type = "1" AND booking_source = "online" THEN 1 END) as online_queue'),
            DB::raw('count(CASE WHEN booking_type = "1" AND booking_source = "walkin" THEN 1 END) as walkin_queue'),
        );

        
        if($input['duration'] == 'monthly'){
            $query->whereDate('booking_date', '>', date('Y-m-d',strtotime('-30 days')));
        }elseif ($input['duration'] == 'today') {
            $query->whereDate('booking_date', '=', date('Y-m-d'));
        }elseif ($input['duration'] == 'custom') {
            if($input['from'] == $input['to']){
                $query->whereDate('booking_date', '=', $input['from']);
            }else{
                $query->whereBetween('booking_date', [$input['from'], $input['to']]);
            }
        }

        //Employe base    
        if (!empty($input['id'])) {
            $query->where("provider_id",$input['id']);
        }

        $query->where("business_id",$business->id);


        $bookingCounter = $query->first();
        
        //$counter = [$created,0,0,0,$visits];
        $counter = [
            0 =>  !empty($bookingCounter->online_queue)? $bookingCounter->online_queue:0,
            1 => !empty($bookingCounter->walkin_queue)? $bookingCounter->walkin_queue:0,
        ];
        // $labels = ['Created','Scheduled','Confirmed','Missed','Shown'];
        // $colors = ['#793BE3','#47ceff','#19BE93','#ff470e','#FFCB46'];

        $data["counter"][0] = ['data'=>$counter];
        // ["counter"=>$counter,'labels'=>$labels, "colors"=>$colors]


        return $this->success($data);
         
    }
    public function bookingSummary(Request $request)
    {
        $input = $request->all();
        $user_id = Auth::id();
        $business = Helper::getBusinessByUserId($user_id);
        
        $query = DB::table("bookings")
        ->select(
            DB::raw('count(*) as total_bookings'),
            DB::raw('count(CASE WHEN booking_type = "1" THEN 1 END) as total_queues'),
            DB::raw('count(CASE WHEN booking_type = "2" THEN 1 END) as total_appts'),
            DB::raw('count(CASE WHEN booking_type = "2" AND booking_source != "raq"  THEN 1 END) as total_appts_walk_online'),
            DB::raw('count(CASE WHEN booking_source = "raq" THEN 1 END) as total_raq'),
            DB::raw('count(CASE WHEN deal_id IS NOT NULL  THEN 1 END) as total_deals'),
            DB::raw('count(CASE WHEN booking_type = "1" AND booking_source = "online" THEN 1 END) as online_queue'),
            DB::raw('count(CASE WHEN booking_type = "1" AND booking_source = "walkin" THEN 1 END) as walkin_queue'),
            DB::raw('count(CASE WHEN booking_type = "2" AND booking_source = "online" THEN 1 END) as online_appt'),
            DB::raw('count(CASE WHEN booking_type = "2" AND booking_source = "walkin" THEN 1 END) as walkin_appt'),
           
        );


        
        if($input['duration'] == 'monthly'){
            $query->whereDate('booking_date', '>', date('Y-m-d',strtotime('-30 days')));
        }elseif ($input['duration'] == 'today') {
            $query->whereDate('booking_date', '<', date('Y-m-d'));
        }elseif ($input['duration'] == 'custom') {
            if($input['from'] == $input['to']){
                $query->whereDate('booking_date', '=', $input['from']);
            }else{
                $query->whereBetween('booking_date', [$input['from'], $input['to']]);
            }
        }

        //Employe base    
        if (!empty($input['id'])) {
            $query->where("provider_id",$input['id']);
        }

        $query->where("business_id",$business->id);
        


        $bookingCounter = $query->first();

        $totalQueues = !empty($bookingCounter->total_queues)? $bookingCounter->total_queues:"0";

        $onlineQueues = (!empty($bookingCounter->online_queue ) && !empty($totalQueues) )? ($bookingCounter->online_queue*100)/$totalQueues:"0";
        $walkinQueues = (!empty($bookingCounter->walkin_queue) && !empty($totalQueues) )? ($bookingCounter->walkin_queue*100)/$totalQueues:"0";

        $totalAppts = !empty($bookingCounter->total_appts_walk_online)? $bookingCounter->total_appts_walk_online:"0";


        $onlineAppts = (!empty($bookingCounter->online_appt) && !empty($totalAppts) )? ($bookingCounter->online_appt*100)/$totalAppts:"0";
        $walkinAppts = (!empty($bookingCounter->walkin_appt) && !empty($totalAppts) )? ($bookingCounter->walkin_appt*100)/$totalAppts:"0";
        $counter = [
            'total_bookings' =>  !empty($bookingCounter->total_bookings)? $bookingCounter->total_bookings:"0",
            'total_queues' => !empty($bookingCounter->total_queues)? $bookingCounter->total_queues:"0",
            'total_appts' => !empty($bookingCounter->total_appts)? $bookingCounter->total_appts:"0",
            'total_raq' => !empty($bookingCounter->total_raq)? $bookingCounter->total_raq:"0",
            'total_deals' =>  !empty($bookingCounter->total_deals)? $bookingCounter->total_deals:"0",

            'online_queue' =>  round($onlineQueues),
            'walkin_queue' =>  round($walkinQueues),
            'online_appt' =>  round($onlineAppts),
            'walkin_appt' =>  round($walkinAppts)

        ];
        return $this->success($counter);
         
    }

    public function checkSaleDiff($current=0, $old=0)
    {
        $differencePercent = 0;
        $infaltion = "increase";
        if($current > $old){
            $differencePercent = $this->calcSalePercent($current, $old, 'increase');
            $differencePercent = "+".$differencePercent;
            $infaltion = "increase";
        }else if($current < $old){
            $differencePercent = $this->calcSalePercent($current, $old, 'decrease');
            $differencePercent = "-".$differencePercent;
            $infaltion = "decrease";
        }

        return $differencePercent;
    }


    public function calcSalePercent($current = 0, $old = 0, $type="increase")
    {
        $percentage = 0;

        if($type == 'increase'){
            if(empty($old)){
                $percentage = 100;
            }else{
                $percentage = ( ($current - $old) / $old ) * 100;    
            }
        }else if($type == 'decrease'){
            $percentage = ( ($old - $current) / $old ) * 100;
        }
        if($percentage > 100){
            $percentage = 100;
        }
        return round($percentage);
    }

}