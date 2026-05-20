<?php

namespace Database\Factories;

use App\Article;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory para modelos Article (tests / seeds).
 */
class ArticleFactory extends Factory
{
    /**
     * Nombre del modelo asociado.
     *
     * @var class-string<Article>
     */
    protected $model = Article::class;

    /**
     * Define el estado por defecto del modelo.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Variables locales para datos sintéticos coherentes con la factory anterior basada en legacy.
        $bar_code = rand(1000000000000, 9999999999999);
        $b_c = rand(1, 2);
        $user_id = rand(3, 4);

        return [
            'online'       => 1,
            'bar_code'     => $b_c == 1 ? $bar_code : null,
            'name'         => $this->faker->name(),
            'cost'         => 70,
            'price'        => 100,
            'online_price' => 100,
            'stock'        => rand(10, 25),
            'user_id'      => $user_id,
            'category_id'  => $user_id == 3 ? rand(1, 4) : rand(5, 8),
        ];
    }
}
