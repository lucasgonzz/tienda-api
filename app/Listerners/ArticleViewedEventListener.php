<?php

namespace App\Listerners;

use App\Events\ArticleViewedEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Session;
use App\View;

class ArticleViewedEventListener
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
     * @param  ArticleViewedEvent  $event
     * @return void
     */
    public function handle(ArticleViewedEvent $event)
    {
        $article = $event->article;
        $buyer_id = $event->buyer_id;
        $key = 'article_'.$article->id;
        if ( ! session()->has($key)) {
            session([$key => time()]);
            $this->saveView($article, $buyer_id);
        } else {
            $this->cleanExpiredView($key, $article, $buyer_id);
        }
    }

    public function saveView($article, $buyer_id) {
        View::create([
            'buyer_id' => $buyer_id,
            'viewable_id' => $article->id,
            'viewable_type' => 'App\Article'
        ]);
    }

    public function cleanExpiredView($key, $article, $buyer_id) {
        $time = time();
        $limit = 3600;
        $timestamp = session($key);
        if ($timestamp + $limit < $time) {
            session()->forget($key);
            $this->saveView($article, $buyer_id);
        } 
    }
}
