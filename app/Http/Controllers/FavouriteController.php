<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use App\Traits\ApiResponser;
// use App\Helpers\Helper;
use App\Models\Favourite;

class FavouriteController extends Controller
{
    use ApiResponser;

    public function favourites($type,$id){
        $favs = [];
        if ($type == 'user') {
            $favs = Favourite::with('business')->where('user_id',$id)->where('status', 1)->get();
        }elseif ($type == 'business') {
            $favs = Favourite::with('user')->where('business_id',$id)->where('status', 1)->get();
        }
        return $this->success($favs);
    }
    public function unFavourite(Request $request){
        $user_id = Auth::id();
        $favs = Favourite::where('id', $request->fav_id)->where('user_id',$user_id)->update(['status' => 0,'updated_at'=>date('Y-m-d H:i:s')]);
        if ($favs) {
            return $this->success('','Un-favourite successfully!');
        }else{
            return $this->error('Something went wrong while making un-favourite');
        }
    }

    public function doFavourite(Request $request){
        if (Auth::check()) {
            $validator = Validator::make($request->all(), [
                'user_id' => 'required',
                'business_id' => 'required'
            ]);
            if ($validator->fails()) {
                $j_errors = $validator->errors();
                $errors = (array) json_decode($j_errors);
                $key = array_key_first($errors);
                return $this->error($errors[$key][0],"",422);
            }

            $is_fav = Favourite::where('user_id', $request->user_id)->where('business_id', $request->business_id)->first();
            if (!empty($is_fav)) {
                if ($is_fav->status == 0) {
                    $fav = Favourite::where('user_id', $request->user_id)->where('business_id', $request->business_id)->update([
                        'status' => 1,
                        'updated_at'=>date('Y-m-d H:i:s')
                    ]);
                }else{
                    $fav = Favourite::where('user_id', $request->user_id)->where('business_id', $request->business_id)->update([
                        'status' => 0,
                        'updated_at'=>date('Y-m-d H:i:s')
                    ]);
                }
            }else{
                $fav = Favourite::create([
                    'user_id' => $request->user_id,
                    'business_id' => $request->business_id,
                    'created_at'=>date('Y-m-d H:i:s'),
                    'updated_at'=>date('Y-m-d H:i:s')
                ]);
            }

            if(!$fav){
                return $this->error("Something went wrong while doing favourite");
            }else{
                return $this->success();
            }
        }else{
            return $this->notLogin();
        }
    }

}