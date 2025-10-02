<?php

namespace App\Http\Controllers;

use Illuminate\Support\Str;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Stripe\Stripe;

class APIController extends Controller
{
    // ===== AUTH PART ======
    // Register API
    public function register(Request $request)
    {
        $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|string|email|unique:users',
            'phone'    => 'required|string|max:30',
            'password' => 'required|string|min:6|confirmed',
        ]);

        $id = DB::table('users')->insertGetId([
            'name'          => $request->name,
            'email'         => $request->email,
            'phone'         => $request->phone,
            'password'      => Hash::make($request->password),
            'user_type'     => 'user',
            'created_at'    => Carbon::now(),
            'updated_at'    => Carbon::now(),
        ]);

        $user = DB::table('users')->where('id', $id)->first();

        // Sanctum token তৈরি
        $plainToken = Str::random(80);
        DB::table('personal_access_tokens')->insert([
            'tokenable_type' => 'App\\Models\\User',
            'tokenable_id'   => $id,
            'name'           => 'authToken',
            'token'          => hash('sha256', $plainToken),
            'abilities'      => '["*"]',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        return response()->json([
            'status'  => true,
            'message' => 'User registered successfully',
            'token'   => $plainToken,
            'user'    => $user
        ], 201);
    }

    // Login API
    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|string|email',
            'password' => 'required|string',
        ]);

        $user = DB::table('users')->where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['status' => false, 'message' => 'Invalid credentials'], 401);
        }

        // নতুন টোকেন তৈরি
        $plainToken = Str::random(80);
        DB::table('personal_access_tokens')->insert([
            'tokenable_type' => 'App\\Models\\User',
            'tokenable_id'   => $user->id,
            'name'           => 'authToken',
            'token'          => hash('sha256', $plainToken),
            'abilities'      => '["*"]',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        return response()->json([
            'status'  => true,
            'message' => 'Login successful',
            'token'   => $plainToken,
            'user'    => $user
        ], 200);
    }

    // Profile
    public function profile(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'status' => true,
            'user'   => $user
        ]);
    }

    // Logout
    public function logout(Request $request)
    {
        // সব টোকেন মুছে ফেলা
        DB::table('personal_access_tokens')
            ->where('tokenable_id', $request->user()->id)
            ->delete();

        return response()->json([
            'status'  => true,
            'message' => 'Logged out successfully'
        ]);
    }

    // Forgot Password
    public function forgetPassword(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $user = DB::table('users')->where('email', $request->email)->first();

        if (!$user) {
            return response()->json(['status' => false, 'message' => 'Email not found'], 404);
        }

        $token = Str::random(60);

        DB::table('users')->where('id', $user->id)->update([
            'remember_token' => $token,
            'updated_at' => now()
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Password reset link generated successfully',
            'reset_link' => url('/api/reset-password?token=' . $token)
        ]);
    }

    // Reset Password
    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => 'required',
            'password' => 'required|min:6|confirmed'
        ]);

        $user = DB::table('users')->where('remember_token', $request->token)->first();

        if (!$user) {
            return response()->json(['status' => false, 'message' => 'Invalid or expired token'], 400);
        }

        DB::table('users')->where('id', $user->id)->update([
            'password' => Hash::make($request->password),
            'remember_token' => null,
            'updated_at' => now()
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Password reset successful'
        ]);
    }

    // ===== HOME PAGE ======
    public function homePage()
    {
        // 🎬 Recent Live TVs (max 10)
        $liveTVs = DB::table('live_tvs')
            ->where('status', 'active')
            ->orderBy('ordering', 'asc')
            ->limit(10)
            ->get(['id','category_id','name','slug','logo','stream_url','schedule_time']);

        // 🎬 Live TV Categories
        $liveTVCategories = DB::table('live_tv_categories')
            ->where('status', 'active')
            ->orderBy('ordering', 'asc')
            ->get(['id','name','slug','icon']);

        // 🎥 Recent Movies (max 10)
        $movies = DB::table('movies')
            ->where('status', 1)
            ->orderBy('id', 'desc')
            ->limit(10)
            ->get(['id','thumbnail','poster','poster_tv','name','slug','genres','imdb_rating','release_date']);

        // 🎥 Movie Categories
        $movieCategories = DB::table('movie_categories')
            ->where('status', 1)
            ->orderBy('id', 'asc')
            ->get(['id','name','slug']);

        // 📰 Ads (home-page only)
        $ads = DB::table('ad_manager')
            ->where('ad_page', 'home-page')
            ->where('ad_status', 1)
            ->get(['id','ad_title','ad_img','ad_link']);

        // ❓ FAQs
        $faqs = DB::table('faqs')
            ->where('status', 'active')
            ->orderBy('sort_order', 'asc')
            ->get(['id','question','answer']);

        return response()->json([
            'status' => true,
            'message' => 'Home Page Data',
            'live_tvs' => $liveTVs,
            'live_tv_categories' => $liveTVCategories,
            'movies' => $movies,
            'movie_categories' => $movieCategories,
            'ads' => $ads,
            'faqs' => $faqs
        ]);
    }

    // =========================
    // ALL LIVE TV API (Category Filter)
    // =========================
    public function allLiveTVs(Request $request)
    {
        $query = DB::table('live_tvs')->where('status', 'active');

        if ($request->has('category_id') && !empty($request->category_id)) {
            $query->where('category_id', $request->category_id);
        }

        $liveTVs = $query->orderBy('ordering', 'asc')->get();

        return response()->json([
            'status' => true,
            'message' => 'All Live TVs',
            'data' => $liveTVs
        ]);
    }

    // =========================
    // ALL MOVIES API (Category Filter)
    // =========================
    public function allMovies(Request $request)
    {
        $query = DB::table('movies')->where('status', 1);

        if ($request->has('category_id') && !empty($request->category_id)) {
            $categoryId = (string) $request->category_id;

            // genres কলামে JSON string থাকে, তাই LIKE দিয়ে match করতে হবে
            $query->where('genres', 'like', '%"'.$categoryId.'"%');
        }

        $movies = $query->orderBy('id', 'desc')->get();

        return response()->json([
            'status' => true,
            'message' => 'All Movies',
            'data' => $movies
        ]);
    }

    // =========================
    // SEARCH PART
    // =========================
    public function search(Request $request)
    {
        $request->validate([
            'query' => 'required|string|max:255',
        ]);

        $search = $request->query('query');

        // --------------------
        // Live TVs
        // --------------------
        $liveTVs = DB::table('live_tvs')
            ->where('status', 'active')
            ->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('slug', 'like', "%{$search}%");
            })
            ->orderBy('ordering', 'asc')
            ->get(['id','category_id','name','slug','logo','stream_url']);

        // --------------------
        // Movies
        // --------------------
        $movies = DB::table('movies')
            ->where('status', 1)
            ->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('slug', 'like', "%{$search}%");
            })
            ->orderBy('id', 'desc')
            ->get(['id','name','slug','poster','poster_tv','thumbnail']);

        // --------------------
        // Series
        // --------------------
        $series = DB::table('series')
            ->where('status', 'active')
            ->where('title', 'like', "%{$search}%")
            ->orderBy('id', 'desc')
            ->get(['id','title','poster','banner','description']);

        // --------------------
        // Radios
        // --------------------
        $radios = DB::table('radios')
            ->where('status', 'active')
            ->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('slug', 'like', "%{$search}%");
            })
            ->orderBy('ordering', 'asc')
            ->get(['id','name','slug','logo','stream_url']);

        // --------------------
        // Events
        // --------------------
        $events = DB::table('events')
            ->where('status', 'active')
            ->where('title', 'like', "%{$search}%")
            ->orderBy('ordering', 'asc')
            ->get(['id','title','slug','thumbnail','banner','start_at','end_at']);

        // --------------------
        // Return JSON
        // --------------------
        return response()->json([
            'status' => true,
            'query' => $search,
            'live_tvs' => $liveTVs,
            'movies' => $movies,
            'series' => $series,
            'radios' => $radios,
            'events' => $events
        ]);
    }

    // =========================
    // Movies, Series, Event Details
    // =========================

    public function details($type, $id)
    {
        if(!in_array($type, ['movie','series','event'])) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid type'
            ], 400);
        }

        if($type == 'movie') {
            $movie = DB::table('movies')->where('id', $id)->first();
            if(!$movie) return response()->json(['status'=>false,'message'=>'Movie not found'],404);

            // Language Name
            $language = null;
            if($movie->language_id) {
                $language = DB::table('languages')->where('id', $movie->language_id)->value('name');
            }

            // Genres Name
            $genreNames = [];
            $genreIds = [];
            if(!empty($movie->genres)) {
                $genreIds = json_decode($movie->genres, true);
                $genreNames = DB::table('movie_categories')
                    ->whereIn('id', $genreIds)
                    ->pluck('name');
            }

            // Actors Name
            $actorNames = [];
            if(!empty($movie->actors)) {
                $actorIds = json_decode($movie->actors, true);
                $actorNames = DB::table('actors')
                    ->whereIn('id', $actorIds)
                    ->pluck('name');
            }

            // Directors Name
            $directorNames = [];
            if(!empty($movie->directors)) {
                $directorIds = json_decode($movie->directors, true);
                $directorNames = DB::table('directors')
                    ->whereIn('id', $directorIds)
                    ->pluck('name');
            }

            // Related Movies (same genres, exclude current)
            $relatedMovies = [];
            if(!empty($genreIds)) {
                $relatedMovies = DB::table('movies')
                    ->where('status', 1)
                    ->where('id', '<>', $id)
                    ->where(function($q) use ($genreIds) {
                        foreach($genreIds as $gid) {
                            $q->orWhereJsonContains('genres', $gid);
                        }
                    })
                    ->limit(10)
                    ->get(['id','name','slug','poster','poster_tv','thumbnail']);
            }

            $movie->language_name = $language;
            $movie->genre_names = $genreNames;
            $movie->actor_names = $actorNames;
            $movie->director_names = $directorNames;

            return response()->json([
                'status' => true,
                'type' => 'movie',
                'data' => $movie,
                'related' => $relatedMovies
            ]);
        }

        if($type == 'series') {
            $series = DB::table('series')->where('id', $id)->first();
            if(!$series) return response()->json(['status'=>false,'message'=>'Series not found'],404);

            // Genres Name
            $genreNames = [];
            $genreIds = [];
            if(!empty($series->genres)) {
                $genreIds = json_decode($series->genres, true);
                $genreNames = DB::table('movie_categories')
                    ->whereIn('id', $genreIds)
                    ->pluck('name');
            }

            // Related Series
            $relatedSeries = [];
            if(!empty($genreIds)) {
                $relatedSeries = DB::table('series')
                    ->where('status', 'active')
                    ->where('id', '<>', $id)
                    ->where(function($q) use ($genreIds) {
                        foreach($genreIds as $gid) {
                            $q->orWhereJsonContains('genres', $gid);
                        }
                    })
                    ->limit(10)
                    ->get(['id','title','poster','banner']);
            }

            $series->genre_names = $genreNames;

            return response()->json([
                'status' => true,
                'type' => 'series',
                'data' => $series,
                'related' => $relatedSeries
            ]);
        }

        if($type == 'event') {
            $event = DB::table('events')->where('id', $id)->first();
            if(!$event) return response()->json(['status'=>false,'message'=>'Event not found'],404);

            // Language Name
            $language = null;
            if($event->language_id) {
                $language = DB::table('languages')->where('id', $event->language_id)->value('name');
            }

            // Category Name
            $category = null;
            if($event->category_id) {
                $category = DB::table('movie_categories')->where('id', $event->category_id)->value('name');
            }

            // Related Events (same category)
            $relatedEvents = [];
            if($event->category_id) {
                $relatedEvents = DB::table('events')
                    ->where('status', 'active')
                    ->where('category_id', $event->category_id)
                    ->where('id', '<>', $id)
                    ->limit(10)
                    ->get(['id','title','slug','thumbnail','banner','start_at','end_at']);
            }

            $event->language_name = $language;
            $event->category_name = $category;

            return response()->json([
                'status' => true,
                'type' => 'event',
                'data' => $event,
                'related' => $relatedEvents
            ]);
        }
    }

    // =========================
    // Radio Details
    // =========================
    public function radioDetails($id)
    {
        $radio = DB::table('radios')->where('id', $id)->first();
        if(!$radio) return response()->json(['status'=>false,'message'=>'Radio not found'],404);

        // Language Name
        $language = null;
        if($radio->language_id) {
            $language = DB::table('languages')->where('id', $radio->language_id)->value('name');
        }

        // Related Radios (same category, exclude current)
        $relatedRadios = [];
        if($radio->category_id) {
            $relatedRadios = DB::table('radios')
                ->where('status', 'active')
                ->where('category_id', $radio->category_id)
                ->where('id', '<>', $id)
                ->limit(10)
                ->get(['id','name','slug','logo','description','stream_url']);
        }

        $radio->language_name = $language;

        return response()->json([
            'status' => true,
            'data' => $radio,
            'related' => $relatedRadios
        ]);
    }

    // =========================
    // LIVE TV Details
    // =========================

    public function liveTVDetails($id)
    {
        $liveTV = DB::table('live_tvs')->where('id', $id)->first();
        if(!$liveTV) return response()->json(['status'=>false,'message'=>'Live TV not found'],404);

        // Related Live TVs (same category, exclude current)
        $relatedLiveTVs = [];
        if($liveTV->category_id) {
            $relatedLiveTVs = DB::table('live_tvs')
                ->where('status', 'active')
                ->where('category_id', $liveTV->category_id)
                ->where('id', '<>', $id)
                ->limit(10)
                ->get([
                    'id','name','slug','logo','description','stream_type','stream_url','backup_stream_url','schedule_time'
                ]);
        }

        return response()->json([
            'status' => true,
            'data' => $liveTV,
            'related' => $relatedLiveTVs
        ]);
    }

    public function videoPlay($type, $id)
    {
        if(!in_array($type, ['movie','series','event'])) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid type'
            ], 400);
        }

        // ================== MOVIE ==================
        if($type == 'movie') {
            $movie = DB::table('movies')->where('id', $id)->first();
            if(!$movie) return response()->json(['status'=>false,'message'=>'Movie not found'],404);

            // Genres
            $genreIds = !empty($movie->genres) ? json_decode($movie->genres, true) : [];
            $genreNames = !empty($genreIds) 
                ? DB::table('movie_categories')->whereIn('id', $genreIds)->pluck('name') 
                : [];

            // Related Movies
            $relatedMovies = [];
            if(!empty($genreIds)) {
                $relatedMovies = DB::table('movies')
                    ->where('status', 1)
                    ->where('id', '<>', $id)
                    ->where(function($q) use ($genreIds) {
                        foreach($genreIds as $gid) {
                            $q->orWhereJsonContains('genres', $gid);
                        }
                    })
                    ->limit(10)
                    ->get(['id','name','slug','poster','poster_tv','thumbnail']);
            }

            $movie->genre_names = $genreNames;

            return response()->json([
                'status' => true,
                'type' => 'movie',
                'play' => $movie,
                'related' => $relatedMovies
            ]);
        }

        // ================== SERIES ==================
        if($type == 'series') {
            $series = DB::table('series')->where('id', $id)->first();
            if(!$series) return response()->json(['status'=>false,'message'=>'Series not found'],404);

            // Genres
            $genreIds = !empty($series->genres) ? json_decode($series->genres, true) : [];
            $genreNames = !empty($genreIds) 
                ? DB::table('movie_categories')->whereIn('id', $genreIds)->pluck('name') 
                : [];

            // Related Series
            $relatedSeries = [];
            if(!empty($genreIds)) {
                $relatedSeries = DB::table('series')
                    ->where('status', 'active')
                    ->where('id', '<>', $id)
                    ->where(function($q) use ($genreIds) {
                        foreach($genreIds as $gid) {
                            $q->orWhereJsonContains('genres', $gid);
                        }
                    })
                    ->limit(10)
                    ->get(['id','title','poster','banner']);
            }

            $series->genre_names = $genreNames;

            return response()->json([
                'status' => true,
                'type' => 'series',
                'play' => $series,
                'related' => $relatedSeries
            ]);
        }

        // ================== EVENT ==================
        if($type == 'event') {
            $event = DB::table('events')->where('id', $id)->first();
            if(!$event) return response()->json(['status'=>false,'message'=>'Event not found'],404);

            // Category
            $category = $event->category_id 
                ? DB::table('movie_categories')->where('id', $event->category_id)->value('name') 
                : null;

            // Related Events
            $relatedEvents = [];
            if($event->category_id) {
                $relatedEvents = DB::table('events')
                    ->where('status', 'active')
                    ->where('category_id', $event->category_id)
                    ->where('id', '<>', $id)
                    ->limit(10)
                    ->get(['id','title','slug','thumbnail','banner','start_at','end_at']);
            }

            $event->category_name = $category;

            return response()->json([
                'status' => true,
                'type' => 'event',
                'play' => $event,
                'related' => $relatedEvents
            ]);
        }
    }

    // ==== ENTERTAINMENT PAGE ===
    public function entertainment()
    {
        // 🎬 Recent Movies
        $recentMovies = DB::table('movies')
            ->where('status', 1)
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get(['id','name','slug','poster','thumbnail','imdb_rating']);

        // 🎥 Top Rated Movies
        $topRatedMovies = DB::table('movies')
            ->where('status', 1)
            ->orderBy('imdb_rating', 'desc')
            ->limit(10)
            ->get(['id','name','slug','poster','thumbnail','imdb_rating']);

        // 🎭 Recent Events
        $recentEvents = DB::table('events')
            ->where('status', 'active')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get(['id','title','slug','thumbnail','banner','start_at','end_at']);

        // ⭐ Most Famous Events (ধরা যাক most famous মানে বেশি order বা পুরোনো → top ordering)
        $famousEvents = DB::table('events')
            ->where('status', 'active')
            ->orderBy('ordering', 'desc')
            ->limit(10)
            ->get(['id','title','slug','thumbnail','banner','start_at','end_at']);

        // 📺 Recent Series
        $recentSeries = DB::table('series')
            ->where('status', 'active')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get(['id','title','poster','banner','imdb_rating']);

        // 🔥 Popular Series (ধরা যাক জনপ্রিয় মানে imdb_rating বেশি)
        $popularSeries = DB::table('series')
            ->where('status', 'active')
            ->orderBy('imdb_rating', 'desc')
            ->limit(10)
            ->get(['id','title','poster','banner','imdb_rating']);

        return response()->json([
            'status' => true,
            'page'   => 'entertainment',
            'movies' => [
                'recent' => $recentMovies,
                'top_rated' => $topRatedMovies
            ],
            'events' => [
                'recent' => $recentEvents,
                'most_famous' => $famousEvents
            ],
            'series' => [
                'recent' => $recentSeries,
                'popular' => $popularSeries
            ]
        ]);
    }

    // ✅ সব Active Category List
    public function categories()
    {
        $categories = DB::table('movie_categories')
            ->where('status', 1)
            ->get();

        return response()->json([
            'status' => true,
            'categories' => $categories
        ]);
    }

    // ✅ নির্দিষ্ট Category এর Movies (age_restricted = 0)
    public function moviesByCategory($id)
    {
        $movies = DB::table('movies')
            ->where('status', 1)
            ->where('age_restricted', 0)
            ->whereRaw("JSON_CONTAINS(genres, '\"$id\"')")
            ->get();

        return response()->json([
            'status' => true,
            'category_id' => (int)$id,
            'movies' => $movies
        ]);
    }

    // Popular Radios API
    public function popularRadios()
    {
        $radios = DB::table('radios')
            ->where('status', 'active')
            ->orderBy('ordering', 'asc')
            ->get();

        return response()->json([
            'status' => true,
            'radios' => $radios
        ]);
    }

    public function download($type, $id)
    {
        // কোন টেবিল থেকে আসবে চেক
        if ($type === 'movie') {
            $item = DB::table('movies')->where('id', $id)->first();
        } elseif ($type === 'series') {
            $item = DB::table('series')->where('id', $id)->first();
        } elseif ($type === 'event') {
            $item = DB::table('events')->where('id', $id)->first();
        } else {
            return response()->json(['status' => false, 'message' => 'Invalid type']);
        }

        if (!$item) {
            return response()->json(['status' => false, 'message' => 'Item not found']);
        }

        // যদি downloadable = 1 হয় তখনই link দেবে
        if (isset($item->downloadable) && $item->downloadable == 1) {
            return response()->json([
                'status' => true,
                'id' => $item->id,
                'type' => $type,
                'name' => $item->name ?? '',
                'video_url' => $item->video_url ?? '',
                'poster' => $item->poster ?? null
            ]);
        }

        return response()->json(['status' => false, 'message' => 'Download not allowed for this item']);
    }

    // ==== NOTIFICATION PART ====
    public function notifications(){
        $notifications = DB::table('notifications')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => true,
            'count' => $notifications->count(),
            'notifications' => $notifications
        ]);
    }

    // ==== PAGES =====
    public function fqa_page(){
        $faqs = DB::table('faqs')
            ->where('status', 'active')
            ->orderBy('sort_order', 'asc')
            ->get();

        return response()->json([
            'status' => true,
            'count' => $faqs->count(),
            'faqs' => $faqs
        ]);
    }

    public function aboutUs()
    {
        $page = DB::table('pages')
            ->where('slug', 'about-us')
            ->where('status', 'published')
            ->first();

        return response()->json([
            'status' => $page ? true : false,
            'page' => $page
        ]);
    }

    // ✅ Help & Support Page
    public function helpAndSupport()
    {
        $page = DB::table('pages')
            ->where('slug', 'help-and-support')
            ->where('status', 'published')
            ->first();

        return response()->json([
            'status' => $page ? true : false,
            'page' => $page
        ]);
    }

    // ✅ Terms & Conditions Page
    public function termsAndConditions()
    {
        $page = DB::table('pages')
            ->where('slug', 'terms-and-conditions')
            ->where('status', 'published')
            ->first();

        return response()->json([
            'status' => $page ? true : false,
            'page' => $page
        ]);
    }

    // ✅ Privacy Policy Page
    public function privacyPolicy()
    {
        $page = DB::table('pages')
            ->where('slug', 'privacy-policy')
            ->where('status', 'published')
            ->first();

        return response()->json([
            'status' => $page ? true : false,
            'page' => $page
        ]);
    }

    // ✅ Contact Us Store
    public function contactUs(Request $request)
    {
        $request->validate([
            'name'    => 'required|string|max:150',
            'email'   => 'required|email|max:150',
            'message' => 'required|string'
        ]);

        $id = DB::table('contacts')->insertGetId([
            'name'       => $request->name,
            'email'      => $request->email,
            'message'    => $request->message,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        return response()->json([
            'status'  => true,
            'message' => 'Your message has been submitted successfully.',
            'id'      => $id
        ]);
    }

    // ✅ Feedback Store
    public function feedback(Request $request)
    {
        $request->validate([
            'name'    => 'required|string|max:150',
            'email'   => 'required|email|max:150',
            'rating'  => 'required|integer|min:1|max:5',
            'message' => 'nullable|string'
        ]);

        $id = DB::table('feedback')->insertGetId([
            'name'       => $request->name,
            'email'      => $request->email,
            'rating'     => $request->rating,
            'message'    => $request->message,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        return response()->json([
            'status'  => true,
            'message' => 'Thank you for your feedback.',
            'id'      => $id
        ]);
    }

    // === PLAN PART ===
    public function plans(){
        $plans = DB::table('plans')
            ->where('status', 'active')
            ->orderBy('price', 'asc')
            ->get();

        return response()->json([
            'status' => true,
            'count' => $plans->count(),
            'plans' => $plans
        ]);
    }

    public function userSubscription($user_id){
        $subscriptions = DB::table('subscriptions as s')
            ->join('plans as p', 's.plan_id', '=', 'p.id')
            ->select(
                's.*',
                'p.name as plan_name',
                'p.price as plan_price',
                'p.duration_days as plan_duration',
                'p.description as plan_description'
            )
            ->where('s.user_id', $user_id)
            ->orderBy('s.created_at', 'desc')
            ->get();

        if ($subscriptions->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No subscription found for this user.'
            ]);
        }

        return response()->json([
            'status' => true,
            'count' => $subscriptions->count(),
            'subscriptions' => $subscriptions
        ]); 
    }

    // ✅ Cancel Subscription
    public function cancelSubscription($id)
    {
        $subscription = DB::table('subscriptions')->where('id', $id)->first();

        if (!$subscription) {
            return response()->json([
                'status' => false,
                'message' => 'Subscription not found.'
            ]);
        }

        // Update status to cancelled
        DB::table('subscriptions')->where('id', $id)->update([
            'status' => 'cancelled',
            'updated_at' => now()
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Subscription has been cancelled successfully.'
        ]);
    }


    // === PAYMENT PART ===

    // Stripe Payment
    public function payWithStripe(Request $request)
    {
        $plan = DB::table('plans')->where('id', $request->plan_id)->first();
        $paymentMethod = DB::table('payment_methods')
            ->where('status', 'active')
            ->where('code', 'stripe')
            ->first();

        if(!$plan || !$paymentMethod){
            return response()->json(['error' => 'Invalid plan or payment method'], 400);
        }

        $config = json_decode($paymentMethod->config_json, true);

        Stripe::setApiKey($config['secret_key']);

        $paymentIntent = \Stripe\PaymentIntent::create([
            'amount' => $plan->price * 100, // cents
            'currency' => 'usd',
            'metadata' => ['plan_id' => $plan->id, 'user_id' => $request->user_id],
        ]);

        return response()->json([
            'client_secret' => $paymentIntent->client_secret
        ]);
    }

    // Sentoo Payment
    public function payWithSentoo(Request $request)
    {
        $plan = DB::table('plans')->where('id', $request->plan_id)->first();
        $paymentMethod = DB::table('payment_methods')
            ->where('status', 'active')
            ->where('code', 'sentoo')
            ->first();

        if(!$plan || !$paymentMethod){
            return response()->json(['error' => 'Invalid plan or payment method'], 400);
        }

        $config = json_decode($paymentMethod->config_json, true);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $config['secret_key'],
            'Content-Type' => 'application/json'
        ])->post($config['base_url'].'/payments', [
            'merchant_id' => $config['merchant_id'],
            'amount'      => $plan->price,
            'currency'    => 'USD',
            'reference'   => uniqid('txn_'),
            'callback'    => url('/api/payment/sentoo/callback'),
        ]);

        return $response->json();
    }

    // ✅ Payment Success Handler
    public function paymentSuccess(Request $request)
    {
        $plan_id        = $request->plan_id;
        $transaction_id = $request->transaction_id;
        $amount         = $request->amount;
        $method         = $request->method; // stripe or sentoo
        $userId         = $request->user_id;

        $plan = DB::table('plans')->where('id', $plan_id)->first();

        if(!$plan){
            return response()->json(['error' => 'Invalid Plan'], 400);
        }

        // Subscriptions insert
        $subscriptionId = DB::table('subscriptions')->insertGetId([
            'user_id' => $userId,
            'plan_id' => $plan->id,
            'start_date' => now(),
            'end_date'   => now()->addDays($plan->duration_days),
            'price'      => $plan->price,
            'final_price'=> $plan->price,
            'status'     => 'active',
            'created_at' => now(),
            'updated_at' => now(),
            'name'       => $plan->name,
            'duration'   => $plan->duration_days,
        ]);

        // Transaction insert
        DB::table('subscriptions_transactions')->insert([
            'subscriptions_id' => $subscriptionId,
            'user_id'          => $userId,
            'amount'           => $amount,
            'payment_type'     => $method,
            'payment_status'   => 'paid',
            'transaction_id'   => $transaction_id,
            'created_by'       => $userId,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        return response()->json(['message' => 'Subscription activated successfully']);
    }


    // ✅ My Profile
    public function myProfile($user_id)
    {
        $user = DB::table('users')
            ->select('id', 'name', 'email', 'phone', 'country', 'date_of_birth', 'profile_photo')
            ->where('id', $user_id)
            ->first();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User not found.'
            ]);
        }

        return response()->json([
            'status' => true,
            'data' => $user
        ]);
    }

    // ✅ Update Profile
    public function updateProfile(Request $request, $user_id)
    {
        $request->validate([
            'name'          => 'nullable|string|max:255',
            'email'         => 'nullable|email|max:255',
            'phone'         => 'nullable|string|max:30',
            'country'       => 'nullable|string|max:255',
            'date_of_birth' => 'nullable|date',
            'profile_photo' => 'nullable|file|mimes:jpg,jpeg,png|max:2048'
        ]);

        $data = [
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'country' => $request->country,
            'date_of_birth' => $request->date_of_birth,
            'updated_at' => now()
        ];

        // Profile photo upload
        if ($request->hasFile('profile_photo')) {
            $file = $request->file('profile_photo');
            $extension = $file->getClientOriginalExtension();
            $randomStr = substr(md5(time() . rand()), 0, 6);
            $fileName = "user_{$user_id}_{$randomStr}.{$extension}";

            // Save to public/uploads/profile
            $file->move(public_path('uploads/profile'), $fileName);

            $data['profile_photo'] = 'uploads/profile/' . $fileName;
        }

        $updated = DB::table('users')->where('id', $user_id)->update($data);

        if ($updated) {
            return response()->json([
                'status' => true,
                'message' => 'Profile updated successfully.',
                'data' => DB::table('users')->select('id', 'name', 'email', 'phone', 'country', 'date_of_birth', 'profile_photo')->where('id', $user_id)->first()
            ]);
        }

        return response()->json([
            'status' => false,
            'message' => 'No changes were made.'
        ]);
    }









}
