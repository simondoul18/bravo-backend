<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Traits\ApiResponser;
use App\Models\Blog;
use App\Models\BlogCategory;
use App\Models\BlogComments;
use App\Models\BlogTags;
use App\Models\BlogPostView;
use Illuminate\Support\Facades\Validator;

class BlogController extends Controller
{
    use ApiResponser;

    public function posts(Request $request) {
        $limit = 10;
        $q = Blog::with('category')->with('admin')->with('comments')->where('status', 1);

        if(!empty($request->section)){
            if ($request->section == 'home_page'){
                $q->where('on_homepage', 1);
            }
        }

        if(!empty($request->category)){
            $q->where('category_id', $request->category);
        }
        if(!empty($request->q)){
            $q->where('blog_title','LIKE', '%'.$request->q.'%');
        }

        if (!empty($request->limit)) {
            $limit = $request->limit;
        }

        if(!empty($request->type)){
            if ($request->type == 'featured'){
                $q->where('is_featured', 1);
            }elseif ($request->type == 'popular'){
                $q->orderBy('view_count','DESC');
            }elseif ($request->type == 'related' && !empty($request->slug)){
                $post = Blog::where('blog_slug',$request->slug)->where('status',1)->first();
                if(!empty($post)){
                    $q->where('id','!=',$post->id)
                    ->where('category_id',$post->category_id);
                }else{
                    return $this->success([]);
                }
            }
        }

        if(!empty($request->tag)){
            $q->where('blog_tags','LIKE','%'.$request->tag.'%');
        }

        $posts = $q->take($limit)->get();
        return $this->success($posts);
    }
    public function postDetail(Request $request,$slug){
        $post = Blog::with(['category','comments','comments.user:id,name,picture','admin:id,name'])->where('blog_slug',$slug)->first();
        if(empty($post)){
            return $this->error("Post not found.");
        }
        $resp = $this->isUpdateViewLog($post->id,$request->ip());
        if ($resp) {
            $post->increment('view_count');
        }
        return $this->success($post);
    }

    public function categories(Request $request) {
        $q = BlogCategory::where('status', 1);
        if (!empty($request->withPostCounts == true)) {
            $q->withCount('posts');
            $q->orderBy('posts_count','DESC');
        }
        if (!empty($request->limit)) {
            $q->take($request->limit);
        }
        $categories = $q->get();
        return $this->success($categories);
    }
    public function categoryDetail(Request $request,$slug){
        $cate = BlogCategory::where('slug',$slug)->where('status',1)->first();
        if(empty($cate)){
            return $this->error("Category not found.");
        }
        // dd($cate);
        return $this->success($cate);
    }
    public function tags(Request $request) {
        $q = BlogTags::where('status', 1);
        if (!empty($request->limit)) {
            $q->take($request->limit);
        }
        $tags = $q->get();
        return $this->success($tags);
    }

    // Comments
    public function leavePostComment(Request $request){
        $validator = Validator::make($request->all(), [
			'name' => 'required|string',
			'email' => 'required|email',
            'post_id' => 'required',
			'message' => 'required|string',
		]);
		if ($validator->fails()) {
			$j_errors = $validator->errors();
			$errors = (array) json_decode($j_errors);
			$key = array_key_first($errors);
			return $this->error($errors[$key][0],"",422);
		}

        $post = Blog::where('id',$request->post_id)->where('status',1)->first();
        if(!empty($post)){
            $comment = new BlogComments;
            $comment->blog_id = $post->id;
            $comment->name = $request->name;
            $comment->email = $request->email;
            $comment->comment = $request->message;
            $comment->save();
            return $this->success($comment,"Comment successfully added.");
        }
        return $this->error("Invalid post.");
    }

    // Log
    public function isUpdateViewLog($id,$ip){
        $existingView = BlogPostView::where('ip', $ip)
        ->where('blog_id', $id)
        ->whereDate('date', date('Y-m-d'))
        ->first();
        if (empty($existingView)) {
            BlogPostView::create([
                'ip' => $ip,
                'blog_id' => $id,
                'date' => date('Y-m-d')
            ]);
            return true;
        }
        return false;
    }
}
