<?php
namespace App\Traits;
use Illuminate\Support\Facades\Storage;
use Aws\Laravel\AwsFacade;

trait Aws{

    protected function AWS_FileUpload($type, $image, $path)
    {
        if ($type == 'base64') {
            if (preg_match('/^data:image\/(\w+);base64,/', $image)) {
                $data = substr($image, strpos($image, ',') + 1);
                $data = base64_decode($data);
                $imageName = md5(uniqid('deal',TRUE)).'.jpg';
                $path = $path.'/'.$imageName;
                $imgUrl = Storage::disk('s3')->put($path, $data, 'public');
                $imgUrl = Storage::disk('s3')->url($path);
                if (!empty($imgUrl)) {
                    $banner = $imgUrl;
                }else{
                    $banner = Null;
                }
            }else{
                $banner = Null;
            }
        }elseif ($type == 'simple') {
            $imageName = md5(uniqid('gallery',TRUE)).'.jpg';
            $path = $path.'/'.$imageName;
            $imgUrl = Storage::disk('s3')->put('gallery', $image, 'public');
            $imgUrl = Storage::disk('s3')->url($imgUrl);
            if (!empty($imgUrl)) {
                $banner = $imgUrl;
            }else{
                $banner = Null;
            }
        }else{
            $banner = Null;
        }
        return $banner;
    }
    public function AWS_FileDelete($path, $image)
    {
        // $path = 'gallery/dFGZ5HbG2idtsZSi7TKq4cNy84QBFqlwbbcG05or.png';
        return Storage::disk('s3')->delete($path.'/'.$image);
    }
}