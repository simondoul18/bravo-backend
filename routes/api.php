<?php
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BusinessController;
use App\Http\Controllers\DealsController;
use App\Http\Controllers\ServicesController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\CommonController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\StripeController;
use App\Http\Controllers\MessagesController;
use App\Http\Controllers\CampaignsController;
use App\Http\Controllers\GalleryController;
use App\Http\Controllers\FavouriteController;
use App\Http\Controllers\PolicyController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\BlogController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\SubscriptionPlansController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

// User Managment
Route::post('signup', [AuthController::class, 'register']);
Route::post('login', [AuthController::class, 'login']);


Route::group(['middleware' => ['auth:sanctum']], function () {
    Route::get('logout', [AuthController::class, 'logout']);
    Route::post('getUser/{id?}', [UserController::class, 'getUserDetail']);
    Route::post('listYourBusiness', [BusinessController::class, 'listYourBusiness']);
    Route::get('getProfileStatus/{business_id}', [AuthController::class, 'getProfileStatus']);

    // Dashboard
    Route::post('source_queue', [DashboardController::class, "sourceQueues"]);
    Route::post('appt_counter', [DashboardController::class, "apptCounters"]);

    Route::post('/booking_summary', [DashboardController::class, "bookingSummary"]);
    Route::any('/sale_stats', [DashboardController::class, "saleStats"]);
    Route::any('/booking_stats', [DashboardController::class, "bookingStats"]);


    // Employee
    Route::post('addEmployee', [BusinessController::class, 'addEmployee']);
    // Route::get('employees', [BusinessController::class, 'businessEmployees']);
    Route::get('employees_list', [BusinessController::class, 'employeesList']);
    Route::get('employee_detail/{id}/{business_id}', [BusinessController::class, 'employeeDetail']);
    Route::post('edit_employee', [BusinessController::class, 'editEmployee']);

    // Services
    Route::post('addNewService', [ServicesController::class, 'addService']);
    Route::get('businessServices/{id?}', [ServicesController::class, 'getBusinessServices']);
    Route::get('deleteBusinessService/{id}', [ServicesController::class, 'deleteBusinessService']);
    Route::get('businessCategories/{id?}', [ServicesController::class, 'getBusinessCategoires']);
    Route::post('employeeServices', [ServicesController::class, 'getEmployeeServices']);

    // Deals
    Route::post('dt_deals', [DealsController::class, 'dtDealsList']);
    Route::get('deal_detail/{slug}', [DealsController::class, 'dealDetail']);
    Route::post('add_new_deal', [DealsController::class, 'addNewDeal']);
    Route::put('edit_deal/{slug}', [DealsController::class, 'editDeal']);
    Route::put('update_deal_status', [DealsController::class, 'updateDealStatus']);

    // Booking and Queue
    Route::post('book_now', [BookingController::class, 'makeBooking']);
    Route::get('bookings', [BookingController::class, 'getBookings']);
    Route::post('join_queue', [BookingController::class, 'joinQueue']);
    Route::post('reuested_services_booking', [BookingController::class, 'reuestedServicesBooking']);
    Route::post('dt_appointments/{from}/{business_id?}/{type?}', [BookingController::class, 'dtAppointments']);
    Route::post('update_booking_status', [BookingController::class, 'updateBookingStatus']);
    Route::post('get_employee_queues', [BookingController::class, 'getQueuesByEmployee']);
    Route::get('queues/{business_id}', [BookingController::class, 'getEmployeesWithQueues']);
    Route::get('client_queues', [BookingController::class, 'getClientQueues']);
    Route::get('updateQueueSettings/{value}/{value2}', [SettingsController::class, 'updateQueueSettings']);
    Route::get('getUserSetting', [SettingsController::class, 'getUserSetting']);

    Route::get('bookings_counter/{business_id}', [BookingController::class, 'bookingCounter']);



    // Stripe card
    Route::post('add_payment_method', [StripeController::class, 'addPaymentMethod']);
    Route::post('connect_stripe', [StripeController::class, 'connectStripeAccount']);
    Route::get('check_stripe_account', [StripeController::class, 'checkStripeConnectAccount']);
    Route::post('dt_payout',[StripeController::class,'dt_payout']);
    Route::post('dt_payment_history',[StripeController::class,'dt_payment_history']);
    Route::get('connect_account_details',[StripeController::class,'connectAccountDetails']);
    Route::post('payout',[StripeController::class,'payout']);



    // Business Hours
    Route::get('business_hours/{business_id}/{for?}', [BusinessController::class, 'getBusinessHours']);
    Route::post('update_business_hours', [BusinessController::class, 'updateBusinessHours']);
    Route::get('working_hours_for_calendar/{provider}/{business_id}', [BusinessController::class, 'workingHoursForCalendar']);


    // Gallery
    Route::post('gallery_images', [GalleryController::class, 'galleryImages']);
    Route::post('upload_gallery_images', [GalleryController::class, 'uploadGalleryImages']);
    Route::post('delete_gallery_image', [GalleryController::class, 'deleteGalleryImage']);
    Route::post('update_image_title', [GalleryController::class, 'updateImageTitle']);


    // Request a Quote
    Route::post('add_reuest_a_quote', [BookingController::class, 'addRequestQuote']);
    Route::post('dt_raq', [BookingController::class, 'dt_RequestQuote']);
    Route::post('update_raq/{action}', [BookingController::class, 'updateRAQ']);

    // Messages
    Route::get('orders_for_conversation/{from}/{id?}', [MessagesController::class, 'ordersForConversation']);
    Route::post('create_conversation', [MessagesController::class, 'createConversation']);
    Route::get('converstaions/{from}/{id?}', [MessagesController::class, 'getConversations']);
    Route::get('converstaion_detail/{from}/{id?}/{order_id?}/{type?}', [MessagesController::class, 'converstaionDetail']);
    Route::get('messages/{convId}', [MessagesController::class, 'getMessages']);
    Route::post('send_message', [MessagesController::class, 'sendMessage']);

    Route::get('assigned_businesses', [BusinessController::class, 'assignedBusinesses']);
    Route::get('payment_cards', [BusinessController::class, 'gatPaymentCards']);

    // Business Profile
    Route::get('business_profile/{id}', [BusinessController::class, 'businessProfile']);
    Route::post('update_business_profile', [BusinessController::class, 'updateBusinessProfile']);

    // User Profile
    Route::post('update_detail', [UserController::class, 'updateDetail']);
    Route::get('user_detail', function(Request $request) {
        return auth()->user();
    });

    // Transactions
    Route::post('dt_transactions',[BookingController::class,'dt_transactions']);


    // Campaigns
    Route::post('/dt_campaigns',[CampaignsController::class,'dt_campaigns']);

    //Route::get('send_campaigns/{id}','Campaigns@sendCampaigns');
    Route::post('dt_campaigns_list',[CampaignsController::class, 'dtCampaignsList']);
    Route::get('campaign_detail/{id}',[CampaignsController::class,'campaignDetail']);
    Route::post('change_campaign_status',[CampaignsController::class,'changeCampaignStatus']);


    // Favourites
    Route::get('favourites/{type}/{id}', [FavouriteController::class, 'favourites']);
    Route::post('un_favourite', [FavouriteController::class, 'unFavourite']);
    Route::post('do_favourite', [FavouriteController::class, 'doFavourite']);

    // Policy
    Route::post('update_policy', [PolicyController::class, 'updatePolicy']);
    Route::get('get_policies/{business_id}', [PolicyController::class, 'getPolicies']);
    Route::get('get_policy_statement/{business_id}/{types?}', [PolicyController::class, 'getPolicyStatement']);
    Route::get('delete_policy/{id}/{business_id}', [PolicyController::class, 'deletePolicy']);



    Route::post('/change_campaign_list_status',[CampaignsController::class,'changeCampaignListStatus']);


    Route::get('/campaigns_list',[CampaignsController::class,'campaignsList']);
    Route::post('/add_campaign_list',[CampaignsController::class,'addCampaignList']);
    Route::post('/add_new_campaign',[CampaignsController::class,'addNewCampaign']);
    // Route::post('/campaigns','Campaigns@campaigns');
    //Route::get('get_customers_zipcodes',[CampaignsController::class,'customersZipCodes']);
    //Route::post('campaign_list_users','Campaigns@getCampaignListUsers');
    //Route::get('campaign_list_detail/{id}','Campaigns@campaignListDetail');

    //Route::get('/contact_list','Campaigns@sg_createContactsList');

    //reviews
    Route::post('give_review', [ReviewController::class, 'giveReview']);
    Route::post('review_helpful', [ReviewController::class, 'reviewHelpful']);
    Route::post('review_report', [ReviewController::class, 'reviewReport']);
    Route::get('reviews/{type}/{id}', [ReviewController::class, 'reviews']);
    Route::post('delete_review', [ReviewController::class, 'deleteReview']);


    // Subscription Plans
    Route::get('subscription_plans', [SubscriptionPlansController::class, 'getPlans']);
    Route::get('plan_detail/{business_id}', [SubscriptionPlansController::class, 'getPlanDetail']);
    Route::get('Transaction_detail/{business_id}', [SubscriptionPlansController::class, 'getTransactionDetail']);
    Route::put('update_auto_renew_setting', [SubscriptionPlansController::class, 'updateAutoRenewStatus']);
    Route::put('update_auto_renew_card', [SubscriptionPlansController::class, 'updateAutoRenewCard']);
    Route::put('update_plan', [SubscriptionPlansController::class, 'updatePlan']);

    // Notifications
    Route::group(['prefix' => 'notifications'], function () {
        Route::get('/', [NotificationController::class, 'getNotifications']);
        Route::get('/detail/{id}', [NotificationController::class, 'getNotificationDetail']);
        Route::get('/mark_as_read', [NotificationController::class, 'markAdRead']);
    });


});
Route::get('testBroadcasting', [NotificationController::class, 'testBroadcasting']);



Route::post('upload_user_pic', [UserController::class, 'uploadUserPic']);
Route::get('/usersList', function(Request $request) {
    return User::all();
});

//Route::get('testAuth', [StripeController::class, 'testAuth']);

// Calendar Booking
Route::post('calendar_bookings', [BookingController::class, 'calendarBookings']);
Route::get('booking_detail/{id}', [BookingController::class, 'bookingDetail']);
Route::post('reschedule_booking', [BookingController::class, 'rescheduleBooking']);


//Get Categories
Route::get('categories/{id?}', [ServicesController::class, 'getCategoires']);
Route::get('getbusinessCategories/{id?}', [ServicesController::class, 'getBusinessCategoires']);
//Get Services
Route::get('services/{cate_id?}', [ServicesController::class, 'getServices']);
Route::get('bookingServices/{slug}', [ServicesController::class, 'getBookingServices']);
Route::get('bookingDeal/{slug}', [DealsController::class, 'getBookingDeal']);
// Route::get('bookingServices/{slug}/{location}/{employee?}', [ServicesController::class, 'getBookingServices']);
// Route::get('bookingDeal/{slug}/{location}', [DealsController::class, 'getBookingDeal']);
Route::post('queueServices', [ServicesController::class,'getServicesForQueue']);
Route::get('requestedServices/{slug}', [ServicesController::class,'getRequestedServices']);

// Business Types
Route::get('business_types',[ServicesController::class,'getBusinessTypes']);

//Deal
Route::get('deals', [DealsController::class, 'dealsListing']);
Route::get('home_deals', [DealsController::class, 'homeDeals']);
Route::get('deal_detail_by_slug/{slug}', [DealsController::class, 'dealDetail']);
Route::get('related_deals/{slug}', [DealsController::class, 'relatedDeals']);
// Get States
Route::get('states/{id?}', [CommonController::class, 'states']);
// Get Profssionals
Route::get('professions', [CommonController::class, 'professions']);

// Search
Route::get('search_suggestions/{keyword}', [SearchController::class, 'searchSuggestions']);
Route::get('popular_services', [SearchController::class, 'popularServices']);
Route::get('service_detail_for_search_suggestion/{name}', [ServicesController::class, 'getServiceDetailForSearchSuggestion']);

//location
Route::get('get_address_by_IP', [CommonController::class, 'getAddressByIp']);
Route::get('get_locations/{keyword}', [SearchController::class, 'getLocations']);


// Covid Points
Route::get('covid_points/{type}/{slug?}', [CommonController::class, 'covidPoints']);

// Employees
Route::post('services_employees', [BusinessController::class, 'getServicesEmployees']);
Route::get('get_employees_list', [BusinessController::class, 'employeesList']);
Route::get('mployees_list_for_MS/{id}', [BusinessController::class, 'employeesListForMS']);

//Business
Route::get('business_listing', [BusinessController::class, 'businessListing']);
Route::get('home_businesses', [BusinessController::class, 'homeBusinesses']);
Route::get('business_detail/{slug}', [BusinessController::class, 'businessDetail']);
// Route::post('business_detail/{slug}', [BusinessController::class, 'businessDetail']);
Route::post('list_your_business', [BusinessController::class, 'listYourBusiness']);

Route::post('get_time_slots', [BusinessController::class, 'getTimeSlots']);

//Settings
Route::get('settings', [CommonController::class, 'settings']);

// Front Pages
Route::get('bannerData/{name}', [CommonController::class, 'bannerData']);

//Business
//Route::get('getBusinessProfileDetails/{slug}', [SearchController::class, 'getBusinessProfileDetails']);
//Business
//Route::get('getBusinessProfileInfo/{slug}/{type}', [SearchController::class, 'getBusinessProfileInfo']);


// Blog
Route::get('posts', [BlogController::class, 'posts']);
Route::get('post_detail/{slug}', [BlogController::class, 'postDetail']);
Route::get('blog_categories', [BlogController::class, 'categories']);
Route::get('blog_category_detail/{slug}', [BlogController::class, 'categoryDetail']);
Route::get('blog_tags', [BlogController::class, 'tags']);
Route::post('leave_post_comment', [BlogController::class, 'leavePostComment']);


Route::get('test_email', [BookingController::class, 'testEmail']);
Route::get('test_twilio', [BookingController::class, 'testSMS']);