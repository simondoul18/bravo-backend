<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
//use Illuminate\Support\Carbon;
use App\Traits\ApiResponser;
use App\Helpers\Helper;
use App\Models\User;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Booking;


class MessagesController extends Controller
{
    use ApiResponser;

    public function ordersForConversation($from,$business_id=''){
        $q = Booking::select('bookings.id','tracking_id','bookings.user_id','bookings.business_id');
        if ($from == 'business') {
            if (empty($business_id)) {
                return $this->success([]);
            }
            $q->with('user:id,name');
            $q->where('bookings.business_id',$business_id);
        }elseif ($from == 'client') {
            if (empty(Auth::id())) {
                return $this->success([]);
            }
            $q->with('business:id,title');
            $q->where('bookings.user_id',Auth::id());
        }
        $bookings = $q->leftJoin('conversations', 'conversations.booking_id', '=', 'bookings.id')->whereNull('conversations.booking_id')->where('booking_type',2)->where('booking_status','<',2)->get();
        
        return $this->success($bookings);
    }
    public function createConversation(Request $request){
        $validator = Validator::make($request->all(), [
           'booking' => 'required',
           'message' => 'required',
           'from' => 'required',
        ]);
        if ($validator->fails()) {
            $j_errors = $validator->errors();
            $errors = (array) json_decode($j_errors);
            $key = array_key_first($errors);
            return $this->error($errors[$key][0],"",422);
        }

        $input = $request->all();
        $conversation = new Conversation();

        if ($input['from'] == 'business') {
            if (empty($input['booking']['user']['id'])) {
                return  $this->error('User not found.');
            }
            if (empty($input['business_id'])) {
                return  $this->error('Business is empty.');
            }

            $conversation->business_id = $input['business_id'];
            $conversation->user_id = $input['booking']['user']['id'];
            $conversation->created_from = 'business';
        }elseif ($input['from'] == 'client') {
            if (empty($input['booking']['business']['id'])) {
                return  $this->error('Business not found.');
            }

            $conversation->user_id = Auth::id();
            $conversation->business_id = $input['booking']['business']['id'];
            $conversation->created_from = 'client';
        }

        $conversation->creator_id = Auth::id();
        $conversation->booking_id = $input['booking']['id'];
        $conversation->created_at = date('Y-m-d H:i:s');
        $conversation->updated_at = date('Y-m-d H:i:s');

        if ($conversation->save()) {
            // Send Message
            //request()->merge(['conversation_id' => $conversation->id]);
            //return  self::sendMessage(request());
            return $this->success($conversation->id);
        }else{
            return $this->error("Something Went Wrong!");
        }
    }
    public function getConversations($from,$business_id=''){
        $q = Conversation::with('user:id,name,picture,email,phone,city,state')->with('business:id,title,profile_pic')->with('booking:id,tracking_id,service_rendered,booking_price,booking_date,booking_start_time,booking_status')->with("booking.BoookingServices")->with('req_a_quote:id,tracking_id,price,render_location,booking_date,status')->with("req_a_quote.services")->with(['req_a_quote.offers' => function ($query) {
            $query->orderBy('id', 'desc');
        }]);
        if ($from == 'business') {
            if (empty($business_id)) {
                return $this->error("Business not found.");
            }
            $q->where('business_id',$business_id);
        }elseif($from == 'client') {
            $q->where('user_id',Auth::id());
        }
        $conversation = $q->get();
        return $this->success($conversation);

        //     $type = 0;
        //     $id = Auth::id();
        // $sqlQuery = "SELECT conversations.* ,users.name,users.picture,users.city,users.state 
        //        FROM conversations
        //         LEFT JOIN users ON users.id = conversations.conv_to OR users.id = conversations.conv_from
        //         LEFT JOIN businesses ON businesses.user_id = conv_to OR businesses.user_id = conv_from
        //         WHERE (conv_from = ".$id." OR conv_to = ".$id." )
        //         ORDER BY conversations.status asc
        //     ";
        // $result = DB::select(DB::raw($sqlQuery));
        
        
        // $conversation = Conversation::select('conversations.*','from.name as fromName', 'to.name as toName','from.id as fromid', 'to.id as toid','to.picture','to.city','to.state')
        // ->leftJoin('users as from', 'conversations.conv_from', '=', 'from.id')
        // ->leftJoin('users as to', 'conversations.conv_to', '=', 'to.id')
        // ->where('conversations.conv_from',Auth::id())
        // ->orWhere('conversations.conv_to',Auth::id())
        // ->orderBy('conversations.status','asc')
        // ->get();
        // foreach ($conversation as $key => $value) {
        //     if($value->conv_from == Auth::id() ){
        //         $conversation[$key]->name = $value->toName; 
        //         $conversation[$key]->user_id = $value->toid; 
        //     }else{
        //         $conversation[$key]->name = $value->fromName; 
        //         $conversation[$key]->user_id = $value->fromid; 
        //     }
        //     // return $value;
        // }
        //return $this->success($conversation);
    }
    public function converstaionDetail($from,$business_id='',$id='',$type=''){
        // Validate Order
        if ($from == 'business') {
            if (empty($business_id)) {
                return $this->error("Business not found.");
            }
            if (empty($id)) {
                return $this->error("Booking id not found.");
            }
        }

        // Get booking ID
        $booking_detail = Booking::where('tracking_id',$id)->first();

        $q = Conversation::with('user:id,name,picture,email,phone,city,state')->with('business:id,title,profile_pic')->with('booking:id,tracking_id,service_rendered,booking_price,booking_date,booking_start_time,booking_status')->with("booking.BoookingServices")->with('req_a_quote:id')->where('booking_id',$booking_detail->id);
        if ($from == 'business') {
            $q->where('business_id',$business_id);
        }elseif($from == 'client') {
            $q->where('user_id',Auth::id());
        }
        $conversation = $q->first();
        return $this->success($conversation);
    }
    public function getMessages($convId) {
        if(empty($convId)){
            return $this->error("Something Went Wrong");
        }
        $msgs = Message::with('user:id,name,picture')->with('business:id,title,profile_pic')->where('conversation_id',$convId)->where('status',1)->get();
        return $this->success($msgs);

        //$q = Message::select('messages.*',DB::raw(''),
        
        // 'sender.name as senderName','sender.picture as senderImage','receiver.first_name as receiverFirstName','receiver.last_name as receiverLastName','receiver.picture as receiverImage')
        // ->leftJoin('users as sender', 'sender.id', '=', 'user_messages.sender_id')
        // ->leftJoin('users as receiver', 'receiver.id', '=', 'user_messages.receiver_id')
        // ->where('conversation_id',$convId)->limit(20)->orderBy('id','desc')->get();



        // $where  = '(user_messages.coversation_id = '.$convId.')';
        //     DB::enableQueryLog();
        // $chatConversation =  DB::table('messages')
        //     ->leftjoin('user_messages', 'user_messages.message_id', '=', 'messages.id')
        //     ->leftJoin('users as sender', 'sender.id', '=', 'user_messages.sender_id')
        //     ->leftJoin('users as receiver', 'receiver.id', '=', 'user_messages.receiver_id')
        //     ->select('messages.*', 'user_messages.seen_status','user_messages.deliver_status', 'user_messages.sender_id','user_messages.receiver_id','sender.name as senderName','sender.picture as senderImage','receiver.first_name as receiverFirstName','receiver.last_name as receiverLastName','receiver.picture as receiverImage')
        //     ->where('user_messages.coversation_id', '=', $convId)
        //     ->get();


        //return $this->success($chatConversation);
    }
    public function sendMessage(Request $request) {
        $validator = Validator::make($request->all(), [
           'message' => 'required',
           'conversation_id' => 'required',
           'msg_from' => 'required',
           'receiver_id' => 'required'
        ]);
        if ($validator->fails()) {
            $j_errors = $validator->errors();
            $errors = (array) json_decode($j_errors);
            $key = array_key_first($errors);
            return $this->error($errors[$key][0],"",422);
        }

        if (empty(Auth::id())) {
            return  $this->error('Your session is expired! Please login to continue your conversation.');
        }
        //$sender_id = Auth::id();
        //$receiver_id = $request->receiver_id;

        $message = new Message();
        $message->message = $request->message;
        $message->conversation_id = $request->conversation_id;
        $message->receiver_id = $request->receiver_id;
        $message->user_id = Auth::id();
        if ($request->msg_from == 'business') {
            # From Business
            if (empty($request->business_id)) {
                return  $this->error('No business found!');
            }
            $message->sender_id = $request->business_id;
            $message->sender_type = 1;
            $message->receiver_type = 2;
        }elseif ($request->msg_from == 'client') {
            $message->sender_id = Auth::id();
            $message->sender_type = 2;
            $message->receiver_type = 1;
        }

        if ($message->save()) {
            return $this->success('Message sent successfully');
            // try {
            //    $message->users()->attach($sender_id, ['coversation_id' =>$request->conversation_id, 'receiver_id' => $receiver_id ]);
            //    return $this->success('Message sent successfully');
            // } catch (\Exception $e) {
            //     $message->delete();
            // }
        }
        return  $this->error('Something Went Wrong! Please try again');
    }
}