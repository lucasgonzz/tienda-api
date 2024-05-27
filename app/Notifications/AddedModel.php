<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class AddedModel extends Notification {
    use Queueable;

    public $model_name;
    public $model_id;
    public $check_added_by;
    public $for_user_id;

    public function __construct($model_name, $model_id, $check_added_by, $for_user_id) {
        $this->model_name = $model_name;
        $this->model_id = $model_id;
        $this->check_added_by = $check_added_by;
        $this->for_user_id = $for_user_id;
    }

    public function via($notifiable) {
        return ['broadcast'];
    }

    public function broadcastOn() {
        return 'added_model.'.$this->for_user_id;
    }

    public function broadcastWith() {
        return [
            "foo" => "bar"
        ];
    }

    public function toBroadcast($notifiable) {
        Log::info('Se mando broadcast for_user_id: '.$this->for_user_id);
        return new BroadcastMessage([
            'model_name'        => $this->model_name,
            'model_id'          => $this->model_id,
            // 'added_by'          => null,
            // 'check_added_by'    => $this->check_added_by,
        ]);
    }
}
