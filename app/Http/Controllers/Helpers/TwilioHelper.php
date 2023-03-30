<?php

namespace App\Http\Controllers\Helpers;

use App\Buyer;
use Twilio\Rest\Client;

class TwilioHelper {

    static function sendVerificationCode($phone) {
        $token = getenv("TWILIO_API_SECRET");
        $twilio_sid = getenv("TWILIO_ACCOUNT_SID");
        $twilio_verify_sid = getenv("TWILIO_VERIFY_SID");
        $twilio = new Client($twilio_sid, $token);
        $twilio->verify->v2->services($twilio_verify_sid)
            ->verifications
            ->create($phone, "sms");
    }

    static function checkVerificationCode($phone, $verification_code) {
        $token = getenv("TWILIO_API_SECRET");
        $twilio_sid = getenv("TWILIO_ACCOUNT_SID");
        $twilio_verify_sid = getenv("TWILIO_VERIFY_SID");
        $twilio = new Client($twilio_sid, $token);
        $verification = $twilio->verify->v2->services($twilio_verify_sid)
            ->verificationChecks
            ->create($verification_code, array('to' => $phone));
        if ($verification->valid) {
            return true;
        } else {
            return false;
        }
    }
    
    static function sendNotification($buyer_id, $title, $message) {
        $buyers = Buyer::where('id', $buyer_id)
                        ->whereNotNull('notification_id');
        $identities = $buyers->pluck('notification_id')->toArray();
        $client = new Client(getenv('TWILIO_API_KEY'), getenv('TWILIO_API_SECRET'),
            getenv('TWILIO_ACCOUNT_SID'));
        try {
            $n = $client->notify->v1->services(getenv('TWILIO_NOTIFY_SERVICE_SID'))
                ->notifications
                ->create([
                    'title' => $title,
                    'body' => $message,
                    'identity' => $identities
                ]);
            Log::info($n->sid);
        } catch (TwilioException $e) {
            Log::error($e);
        }    
    }

}
