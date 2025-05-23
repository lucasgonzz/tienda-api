<?php

namespace App\Http\Controllers;

use App\DeliveryDay;
use Illuminate\Http\Request;

class DeliveryDayController extends Controller
{
    public function get_dias_habilitados($commerce_id)
    {
        $enabledDays = DeliveryDay::where('user_id', $commerce_id)
                            ->pluck('day_of_week')->toArray();
        $today = now();
        $dates = [];

        $semanas_delante = 1;

        for ($i = 0; $i < ($semanas_delante * 7); $i++) {
            $date = $today->copy()->addDays($i);
            if (in_array($date->dayOfWeek, $enabledDays)) {
                $dates[] = [
                    'label' => $date->translatedFormat('l j \\d\\e F'), // Ej: "Miércoles 1 de mayo"
                    'value' => $date->toDateString(), // Ej: "2025-05-01"
                ];
            }
        }

        return response()->json(['models' => $dates], 200);
    }
}
