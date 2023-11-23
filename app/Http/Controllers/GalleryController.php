<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Carbon;
use App\Traits\ApiResponser;
use App\Helpers\Helper;
use App\Models\Gallery;
use App\Models\Gallery_report;
use App\Models\Gallery_like;
use App\Traits\Aws;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Storage;

class GalleryController extends Controller
{
    use ApiResponser;
    use Aws;

    public function galleryImages(Request $request){
        $images = Gallery::where('business_id', $request->id)->get();
        return $this->success($images);
    }
    public function uploadGalleryImages(Request $request){
        if (Auth::check()) {
            $validator = Validator::make($request->all(), [
                'id' => 'required',
                'images' => 'required'
            ]);
            if ($validator->fails()) {
                $j_errors = $validator->errors();
                $errors = (array) json_decode($j_errors);
                $key = array_key_first($errors);
                return $this->error($errors[$key][0],"",422);
            }

            if($request->hasfile('images'))
            {
                foreach($request->file('images') as $key => $file)
                {
                    // return $file;

                    $banner = $this->AWS_FileUpload('simple', $file, 'gallery');
                    if ($request->title[$key] == 'undefined') {
                        $image_title = null;
                    }else{
                        $image_title = $request->title[$key];
                    }
                    

                    $gallery = Gallery::create([
                        'business_id' => $request->id,
                        "title" => $image_title,
                        'image' => $banner
                    ]);
                }
            }
            if(!$gallery){
                return $this->error("Something went wrong while uploading images");
            }else{
                return $this->success();
            }
        }else{
            return $this->notLogin();
        }
    }
    public function deleteGalleryImage(Request $request){
        $images = Gallery::where('id', $request->id)->delete();
        if ($images) {
            return $this->success('','Image deleted successfully!');
        }else{
            return $this->error('Something went wrong while deleting image');
        }
    }
    public function updateImageTitle(Request $request)
    {
        $input = $request->all();
        $resp = Gallery::where('id', $input['id'])->where('business_id', $input['business_id'])->update(['title' => $input['title']]);
        if ($resp) {
            return $this->success('', 'Image title updated successfully!');
        }else{
            return $this->error('Something went wrong.');
        }
    }
}