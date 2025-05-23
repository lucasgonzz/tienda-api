<?php

namespace App\Http\Controllers;

use App\Bodega;
use Illuminate\Http\Request;

class BodegaController extends Controller
{
    function index($commerce_id) {
        $models = Bodega::where('user_id', $commerce_id)
                        ->orderBy('name', 'ASC')
                        ->withCount('articles')
                        ->get();

        return response()->json(['models' => $models], 200);
    }
}
