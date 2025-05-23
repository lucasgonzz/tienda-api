<?php

namespace App\Jobs;

use App\Mail\OrderCreated;
use App\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendOrderCreatedEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $order;
    protected $email;

    /**
     * Create a new job instance.
     */
    public function __construct($order, $email)
    {
        Log::info('se creo job');
        $this->order = $order;
        $this->email = $email;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('se mando job');

        Mail::to($this->email)->send(new OrderCreated($this->order));
    }
}
