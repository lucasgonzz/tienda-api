<?php

namespace App\Http\Controllers\Helpers;

class ImageHelper {

	static function image($model = null, $from_model = false, $cropped = true) {
		$image_url = ''; 
		if (is_null($model)) {
			$model = UserHelper::getFullModel();
		}
		if (!$from_model) {
			$image_url = $model->hosting_image_url;
		} else {
			$image_url = $model->{$from_model}->hosting_image_url;
		}
		if (!is_null($image_url)) {
			if (env('APP_ENV') == 'production') {
                $position = strpos($image_url, 'storage');
                $first = substr($image_url, 0, $position);
                $end = substr($image_url, $position);
                return $first.'public/'.$end;
			}
			return $image_url;
		}
	}
	
}