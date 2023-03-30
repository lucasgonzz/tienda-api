<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Configuration;

class ConfigurationController extends Controller
{
    function setQuestionsSeen($value) {
    	$configuration = Configuration::where('buyer_id', $this->buyerId())
    									->first();
    	$configuration->questions_seen = $value;
    	$configuration->save();
    }
}
