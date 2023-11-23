<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Traits\ApiResponser;
use App\Events\NotificatiionEvent;
class NotificationController extends Controller
{
    use ApiResponser;

    public function getNotifications(){
        $unreadNoti = Auth::user()->unreadNotifications->count();
        $notifications = Auth::user()->notifications()->limit(25)->get();
        return $this->success(['allNotifications'=> $notifications, 'unreadNotifications' => $unreadNoti]);
    }
    public function getNotificationDetail($id){
        $noti = Auth::user()->notifications()->find($id);
        return $this->success($noti);
    }

    public function markAdRead(Request $request){
        if (!empty($request->id)) {
            if(strtolower($request->id) == 'all'){
                Auth::user()->unreadNotifications->markAsRead();
            }else{
                Auth::user()->unreadNotifications->where('id', $request->id)->markAsRead();
            }
            return $this->success("","Notification deleted successfully.");
        }else{
            return $this->error("Id not found.");
        }
    }

    public function testBroadcasting()
	{
        broadcast(new NotificatiionEvent);
        echo "Done";
	}
}
