<?php

namespace App\Http\Controllers;

use App\Address;
use App\Http\Controllers\Helpers\StringHelper;
use Illuminate\Http\Request;

class AddressController extends Controller
{
    function store(Request $request) {
        $address = Address::create([
            'street' => $request->street,
            'street_number' => $request->street_number,
            'city' => $request->city,
            'province' => $request->province,
            'lat' => $request->lat,
            'lng' => $request->lng,
            'buyer_id' => $this->buyerId(),
            'depto' => $request->depto,
            'description' => $request->description,
        ]);
        return response()->json(['address' => $address], 201);
    }
}
