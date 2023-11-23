<?php

namespace App\Http\Controllers;
use Illuminate\Support\Facades\Auth;
use App\Traits\ApiResponser;
use Illuminate\Http\Request;
use App\Models\User_setting;
class SettingsController extends Controller
{
    use ApiResponser;

    public function updateQueueSettings($queueStatus, $queueVirtual)
    {
        $id = Auth::id();
        $query = User_setting::where('user_id',$id);
        $setting = $query->first();
        $data = [
            'queue_status' => $queueStatus,
            'queue_walkin' => $queueVirtual,
        ];
        if (!empty($setting)) {
            $resp = $query->update($data);
        }else{
            $data['user_id'] = $id;
            $resp = User_setting::insert($data);
        }
        if ($resp) {
            return $this->success('','Successfully update.');
        }else{
            return $this->error("Something went wrong.");
        }
    }
    public function getUserSetting()
    {
        $id = Auth::id();
        $setting = User_setting::where('user_id',$id)->first();
        return $this->success($setting);
    }
}

