<?php

namespace App\Http\Controllers;

use App\Cepa;
use Illuminate\Http\Request;

class CepaController extends Controller
{
    function index($commerce_id) {
        $models = Cepa::where('user_id', $commerce_id)
                        ->orderBy('name', 'ASC')
                        ->withCount('articles')
                        ->get();

        return response()->json(['models' => $models], 200);
    }
}
