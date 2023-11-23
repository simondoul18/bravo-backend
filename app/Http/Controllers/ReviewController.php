<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use App\Traits\ApiResponser;
use App\Helpers\Helper;
use App\Models\Review;
use App\Models\Review_helpful;
use App\Models\Booking;
use App\Notifications\ReportSubmitted;
use App\Models\Business;

class ReviewController extends Controller
{
    use ApiResponser;

    // Give reviews
    public function giveReview(Request $request){
        if (Auth::check()) {
            $validator = Validator::make($request->all(), [
                'businessReview' => 'required|string',
                'order_id' => 'required',
                'services' => 'required',
                'values' => 'required',
                'punctuality' => 'required',
                'overAll' => 'required'
            ]);
            if ($validator->fails()) {
                $j_errors = $validator->errors();
                $errors = (array) json_decode($j_errors);
                $key = array_key_first($errors);
                return $this->error($errors[$key][0],"",422);
            }

            $input = $request->all();
            $bookingInfo = Booking::select("business_id")->where("id",$input['order_id'])->first();
            if(empty($bookingInfo)){
                return $this->error("Order Id is invalid");
            }
            $avg_rating =  ( ((int)$input['overAll']) + ((int)$input['punctuality']) + ((int)$input['values']) + ((int)$input['services'])) / 4;

            //return $this->success($avg_rating);
            $reviewInfo = array(
                "user_id"=> Auth::id(),
                "business_id"=>!empty($bookingInfo['business_id'])?$bookingInfo['business_id']:0,
                "booking_id"=>!empty($input['order_id'])?$input['order_id']:0,
                "overall"=>!empty($input['overAll'])?$input['overAll']:0,
                "punctuality"=>!empty($input['punctuality'])?$input['punctuality']:0,
                "value"=>!empty($input['values'])?$input['values']:0,
                "services"=>!empty($input['services'])?$input['services']:0,
                "overall_rating"=>$avg_rating,
                "business_review"=>!empty($input['businessReview'])?$input['businessReview']:"",
                "employee_review"=>!empty($input['userReview'])?$input['userReview']:""
            );

            $inserted= Review::create($reviewInfo);
            if($inserted){
                Booking::where("id",$input['order_id'])->update(['review_given' => 1]);
                return $this->success("Review submitted successfully");
            }else{
                return $this->error("Something went wrong");
            }

        }else{
            return $this->error("Not login.");
        }
    }

    //Helpful review
    public function reviewHelpful(Request $request){
        if (Auth::check()) {
            $validator = Validator::make($request->all(), [
                'review_id' => 'required',
                'business_id' => 'required'
            ]);
            if ($validator->fails()) {
                $j_errors = $validator->errors();
                $errors = (array) json_decode($j_errors);
                $key = array_key_first($errors);
                return $this->error($errors[$key][0],"",422);
            }

            $input = $request->all();
            
            $reviewhelpful = array(
                "user_id"=> Auth::id(),
                "business_id"=>!empty($input['business_id'])?$input['business_id']:0,
                "review_id"=>!empty($input['review_id'])?$input['review_id']:0
            );

            $inserted= Review_helpful::create($reviewhelpful);

            if($inserted){
                $counter = Review_helpful::where("review_id",$input['review_id'])->count();
                return $this->success($counter);
            }else{
                return $this->error("Something went wrong");
            }

        }else{
            return $this->error("Not login.");
        }
    }

    //Report Review
    public function reviewReport(Request $request){
        if (Auth::check()) {
            $validator = Validator::make($request->all(), [
                'reviewId' => 'required',
                'businessId' => 'required',
                'subject' => 'required'
            ]);
            if ($validator->fails()) {
                $j_errors = $validator->errors();
                $errors = (array) json_decode($j_errors);
                $key = array_key_first($errors);
                return $this->error($errors[$key][0],"",422);
            }

            $input = $request->all();
            $business = Business::find($input['businessId']); // Replace with your logic to retrieve the business

            $link = "https://bravo.ondaq.com/".$business->title_slug."/reviews";

            $sent = $business->notify(new ReportSubmitted($input['subject'], $input['description'], $input['reportedReview'], $business->title, $link));


            
            return $this->success("Report submitted succssfully");
            
            

        }else{
            return $this->error("Not login.");
        }
    }

    public function Reviews($type,$id){
        $reviews = [];
        if ($type == 'user') {
            $reviews = Review::with('business')->where('user_id',$id)->where('is_deleted',0)->where('status', 1)->get();
        }elseif ($type == 'business') {
            $reviews = Review::with('user')->where('business_id',$id)->where('is_deleted',0)->where('status', 1)->get();
        }
        return $this->success($reviews);
    }

    public function deleteReview(Request $request){
        $user_id = Auth::id();
        $revs = Review::where('id', $request->rev_id)->where('user_id',$user_id)->update(['status' => 0,'is_deleted' => 1,'updated_at'=>date('Y-m-d H:i:s')]);
        if ($revs) {
            return $this->success('','Review deleted successfully!');
        }else{
            return $this->error('Something went wrong while making deleting Review');
        }
    }

}