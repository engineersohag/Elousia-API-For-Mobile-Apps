<?php

use App\Http\Controllers\APIController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// == AUTH PART ==
Route::post('/register', [APIController::class, 'register']);
Route::post('/login', [APIController::class, 'login']);
Route::post('/forgot-password', [APIController::class, 'forgetPassword']);
Route::post('/reset-password', [APIController::class, 'resetPassword']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/profile', [APIController::class, 'profile']);
    Route::post('/logout', [APIController::class, 'logout']);
});

// == HOME PAGE ==
Route::get('/home', [APIController::class, 'homePage']);
Route::get('/live-tvs', [APIController::class, 'allLiveTVs']);
Route::get('/movies', [APIController::class, 'allMovies']);

// == SEARCH PART ==
Route::get('/search', [APIController::class, 'search']);

// == ENTERTAINMENT PAGE ==
Route::get('/entertainment', [APIController::class, 'entertainment']);

// == ELOKIDZ PAGE ==
Route::get('/elokidz/categories', [APIController::class, 'categories']);
Route::get('/elokidz/category/{id}/movies', [APIController::class, 'moviesByCategory']);

// == RADIO PAGE ==
Route::get('/radios', [APIController::class, 'popularRadios']);

// == Details Page ==
Route::get('/details/{type}/{id}', [APIController::class, 'details']);
Route::get('radio/details/{id}', [APIController::class, 'radioDetails']);
Route::get('live-tv/details/{id}', [APIController::class, 'liveTVDetails']);

// == Video Play API (Movies, Series, Events) ==
Route::get('/play/{type}/{id}', [APIController::class, 'videoPlay']);

// == DOWNLOAD PART ==
Route::get('/download/{type}/{id}', [APIController::class, 'download']);

// == NOTIFICATION ==
Route::get('/notifications', [APIController::class, 'notifications']);

// == PAGES ==
Route::get('/faqs', [APIController::class, 'fqa_page']);
Route::get('/about-us', [APIController::class, 'aboutUs']);
Route::get('/help-and-support', [APIController::class, 'helpAndSupport']);
Route::get('/terms-and-conditions', [APIController::class, 'termsAndConditions']);
Route::get('/privacy-policy', [APIController::class, 'privacyPolicy']);

Route::post('/contact-us', [APIController::class, 'contactUs']);
Route::post('/feedback', [APIController::class, 'feedback']);

// === PLAN PART ===
Route::get('/plans', [APIController::class, 'plans']);
Route::get('/subscription/{user_id}', [APIController::class, 'userSubscription']);
Route::post('/subscription/cancel/{id}', [APIController::class, 'cancelSubscription']);

// === PAYMENT PART ===
Route::post('/payment/stripe', [APIController::class, 'payWithStripe']);
Route::post('/payment/sentoo', [APIController::class, 'payWithSentoo']);
Route::post('/payment/success', [APIController::class, 'paymentSuccess']);

// === Profile PART ===
Route::get('/my-account/{user_id}', [APIController::class, 'myProfile']);
Route::post('/update-account/{user_id}', [APIController::class, 'updateProfile']);












