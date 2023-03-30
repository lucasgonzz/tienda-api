<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Title;

class TitleController extends Controller
{
    function index($commerce_id) {
    	$titles = Title::where('user_id', $commerce_id)
    					->get();
    	return response()->json(['titles' => $titles], 200); 
    }
}
