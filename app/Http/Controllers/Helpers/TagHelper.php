<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Helpers\UserHelper;
use App\Tag;

class TagHelper
{

    static function addTagsAndSetId($commerce_id) {
        $tags = Tag::where('user_id', $commerce_id)
                    ->get();
        foreach ($tags as $tag) {
            $tag->id = 'tag-'.$tag->id;
        }
        return $tags;
    }

}
