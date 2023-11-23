<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class FrontendController extends Controller
{
    // For blog application
    public function blog()
    {
        return view('blog');
    }
    // For public application
    public function app()
    {
        return view('app');
    }
}
