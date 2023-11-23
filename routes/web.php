<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\FrontendController;
use App\Http\Controllers\StripeController;
/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

// Route::get('/', function () {
//     return view('welcome');
// });

// Stripe Webhooks
Route::post('/webhook', [StripeController::class, 'getSripeWebhooks'])->where('any', '.*');

Route::get('/test', [StripeController::class, 'test'])->where('any', '.*');

// For blog application
// Route::get('/blog{any}', [FrontendController::class, 'blog'])->where('any', '.*');

// For public application
Route::get('/{any}', [FrontendController::class, 'app'])->where('any', '^(?!api).*$');