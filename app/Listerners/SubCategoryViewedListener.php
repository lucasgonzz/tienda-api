<?php

namespace App\Listerners;

use App\Events\SubCategoryViewed;
use App\View;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SubCategoryViewedListener
{
    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     *
     * @param  SubCategoryViewedEvent  $event
     * @return void
     */
    public function handle(SubCategoryViewed $event)
    {
        $sub_category_id = $event->sub_category_id;
        $buyer_id = $event->buyer_id;
        $key = 'sub_category_'.$sub_category_id;
        if ( ! session()->has($key)) {
            session([$key => time()]);
            $this->saveView($sub_category_id, $buyer_id);
        } else {
            $this->cleanExpiredView($key, $sub_category_id, $buyer_id);
        }
    }

    public function saveView($sub_category_id, $buyer_id) {
        View::create([
            'buyer_id' => $buyer_id,
            'viewable_id' => $sub_category_id,
            'viewable_type' => 'App\SubCategory'
        ]);
    }

    public function cleanExpiredView($key, $sub_category_id, $buyer_id) {
        $time = time();
        $limit = 3600;
        $timestamp = session($key);
        if ($timestamp + $limit < $time) {
            session()->forget($key);
            $this->saveView($sub_category_id, $buyer_id);
        } 
    }
}
