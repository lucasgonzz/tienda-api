<?php

namespace App\Http\Controllers;

use App\Events\QuestionCreatedEvent;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Notifications\QuestionCreated;
use App\Question;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class QuestionController extends Controller
{
    function index() {
    	$questions = Question::where('buyer_id', $this->buyerId())
							->orderBy('created_at', 'DESC')
                            ->with('article.images')
							->with('answer')
							->paginate(5);
    	return response()->json(['questions' => $questions], 200);
    }

    function store(Request $request) {
    	$question = Question::create([
            'text'       => ucfirst($request->text),
    		'buyer_id'   => $this->buyerId(),
            'article_id' => $request->article['id'],
    		'user_id'    => $request->commerce_id
    	]);
        $question_for_event = Question::where('id', $question->id)
                                        ->with('article.images')
                                        ->with('article.variants')
                                        ->with('buyer')
                                        ->first();
        Auth::guard('buyer')->user()->notify(new QuestionCreated($question_for_event));
        // broadcast(new QuestionCreatedEvent($question_for_event))->toOthers();
    	return response(null,201);
    }
}
