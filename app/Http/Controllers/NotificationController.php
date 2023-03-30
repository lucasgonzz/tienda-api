<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use PHPUnit\Exception;
use Twilio\Rest\Client;

class NotificationController extends Controller
{
    function index(Request $request) {
        $page = $request->page;
        $perpage = 6; 
    	$notifications = Auth::guard('buyer')->user()->notifications()->paginate($perpage, ['*'], 'page', $page);
    	return response()->json(['notifications' => $notifications]);
    }

    function unread() {
    	$notifications = Auth::guard('buyer')->user()->unreadNotifications;
    	return response()->json(['notifications' => $notifications]);
    }

    function markAsRead() {
    	Auth::guard('buyer')->user()->unreadNotifications->markAsRead();
    	return response(null, 200);
    }

    public function createBinding(Request $request) {
            $client = new Client(getenv('TWILIO_API_KEY'), getenv('TWILIO_API_SECRET'),
                getenv('TWILIO_ACCOUNT_SID'));
            $service = $client->notify->v1->services(getenv('TWILIO_NOTIFY_SERVICE_SID'));

            $request->validate([
                'token' => 'string|required'
            ]);
            $address = $request->get('token');

            // we are just picking the user with id = 1,
            // ideally, it should be the authenticated user's id e.g $userId = auth()->user()->id
            $user = Auth::guard('buyer')->user();
            $identity = sprintf("%05d", $user->id);
            // attach the identity to this user's record
            $user->update(['notification_id' => $identity]);
            try {
                $binding = $service->bindings->create(
                    $identity,
                    'fcm',
                    $address
                );
                Log::info($binding);
                return response()->json(['message' => 'binding created']);
            } catch (Exception $e) {
                Log::error($e);
                return response()->json(['message' => 'could not create binding'], 500);
            }
        }
}
