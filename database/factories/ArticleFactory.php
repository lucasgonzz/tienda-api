<?php

/** @var \Illuminate\Database\Eloquent\Factory $factory */

use App\Article;
use Faker\Generator as Faker;

$factory->define(Article::class, function (Faker $faker) {
	$cost = rand(50, 3000);
    $bar_code = rand(1000000000000, 9999999999999);
    $b_c = rand(1, 2);
    $user_id = rand(3,4);
    $created_at = Carbon::now()->subDays()
    return [
        'online'       => 1,
    	'bar_code'     => $b_c == 1 ? $bar_code : null,
        'name'         => $faker->name,
        'cost'         => 70,
        'price'        => 100,
        'online_price' => 100,
        'stock'        => rand(10, 25),
        'user_id'      => $user_id,
        // 'image'        => '15886212571.jpg',
        'category_id'  => $user_id == 3 ? rand(1,4) : rand(5,8),
    ];
});
