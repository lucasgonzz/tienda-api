<?php

namespace App\Http\Controllers;

use App\Advise;
use Illuminate\Http\Request;

class AdviseController extends Controller
{

    function store(Request $request) {
        Advise::create([
            'article_id' => $request->article_id,
            'email'      => $request->email,
        ]);
        return response(null, 201);
    }
}
