<?php

namespace App\Http\Controllers;

use App\Condition;
use Illuminate\Http\Request;

class ConditionController extends Controller
{
    function index() {
        $conditions = Condition::all();
        return response()->json(['conditions' => $conditions], 200);
    }
}
