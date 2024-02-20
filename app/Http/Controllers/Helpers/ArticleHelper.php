<?php

namespace App\Http\Controllers\Helpers;

use App\ArticleVariant;
use App\Color;
use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\UserHelper;
use App\PriceType;
use App\Size;
use App\User;
use Illuminate\Support\Facades\Log;

class ArticleHelper
{

    static function checkPriceTypes($articles) {
        $buyer = Auth('buyer')->user();
        if (!is_null($buyer)) {
            if (!is_null($buyer->comercio_city_client) && !is_null($buyer->comercio_city_client->price_type)) {
                $price_types = PriceType::where('user_id', $buyer->user_id)
                                        ->whereNotNull('position')
                                        ->orderBy('position', 'ASC')
                                        ->get();
                foreach ($articles as $article) {
                    foreach ($price_types as $price_type) {
                        if (!is_null($article) && $price_type->position <= $buyer->comercio_city_client->price_type->position) {
                            $percentage = $price_type->percentage;
                            if (count($price_type->sub_categories) >= 1 && !is_null($article->sub_category)) {
                                foreach ($price_type->sub_categories as $price_type_sub_category) {
                                    if ($price_type_sub_category->id == $article->sub_category_id) {
                                        Log::info('Usando el porcetaje de '.$price_type_sub_category->name.' de '.$price_type_sub_category->pivot->percentage);
                                        $percentage = $price_type_sub_category->pivot->percentage;
                                    }
                                }
                            }
                            Log::info('sumando el '.$percentage.'% a '.$article->final_price.' de '.$article->name);
                            $article->final_price += $article->final_price * $percentage / 100;
                        } else {
                            break;
                        }
                    }
                }
            }
        }
        return $articles;
    }

    // static function setPrices($articles) {
    //     if (count($articles) >= 1) {
    //         $commerce = User::find($articles[0]->user_id);
    //         foreach ($articles as $article) {
    //             if (!is_null($article->percentage_gain)) {
    //                 Log::info(Numbers::percentage($article->percentage_gain));
    //                 $article->price = Numbers::redondear($article->cost + ($article->cost * Numbers::percentage($article->percentage_gain)));
    //             }
    //             if (!$commerce->configuration->iva_included) {
    //                 $article->price = Numbers::redondear($article->price + ($article->price * Numbers::percentage($article->iva->percentage)));
    //             }
    //             if (count($article->discounts) >= 1) {
    //                 $article->original_price = $article->price;
    //                 foreach ($article->discounts as $discount) {
    //                     $article->price = Numbers::redondear($article->price - ($article->price * Numbers::percentage($discount->percentage)));
                        
    //                 }
    //             }
    //         }
    //     }
    //     return $articles;
    // }

    static function lastProviderPercentageGain($article) {
        $last_provider = Self::lastProvider($article);
        if (!is_null($last_provider) && !is_null($last_provider->percentage_gain)) {
            return $last_provider->percentage_gain;
        }
        return null;
    }

    static function lastProvider($article) {
        if (count($article->providers) >= 1) {
            $last_provider = $article->providers[count($article->providers)-1];
            if (!is_null($last_provider)) {
                return $last_provider;
            }
        }
        return null;
    }

    static function hasIva($article) {
        return !is_null($article->iva) && $article->iva->percentage != '0' && $article->iva->percentage != 'Exento' && $article->iva->percentage != 'No Gravado'; 
    }

    static function getVariantId($article) {
        if (isset($article['variant'])) {
            return $article['variant']['id'];
        }
        return null;
    }

    static function getColorId($article) {
        if (isset($article['color'])) {
            return $article['color']['id'];
        }
        return null;
    }

    static function getDolar($article, $dolar_blue) {
        if ($article['with_dolar']) {
            return $dolar_blue;
        }
        return null;
    }

    static function getSizeId($article) {
        if (isset($article['size'])) {
            return $article['size']['id'];
        }
        return null;
    }

    static function getFromVariant($article, $variant_id) {
        foreach ($article->variants as $variant) {
            if ($variant->id == $variant_id) {
                // return $variant->description;
                $new_article = Self::createArticle($article, $variant);
            }
        }
        return $new_article;
    }

    static function checkVariantsStock($articles) {
        foreach ($articles as $article) {
            $index = 0;
            foreach ($article->variants as $variant) {
                if (!$variant->stock >= 1) {
                    $variant->description .= ' (sin stock)';
                }
                $index++;
            }
        }
        return $articles;
    }

    static function setFavorites($articles) {
    	foreach ($articles as $article) {
	        if (!is_null($article) && $article->liked(UserHelper::buyerId())) {
	            $article->is_favorite = true;
	        }
    	}
    	return $articles;
        
    }

    static function setVariants($articles) {
        $new_articles = [];
        foreach ($articles as $article) {
            if (count($article->variants) >= 1) {
                foreach ($article->variants as $variant) {
                    $new_article = Self::createArticle($article, $variant);
                    $new_articles[] = $new_article;
                }
            } else {
                $article->is_variant = false;
                $article->key = $article->id;
                $new_articles[] = $article;
            }
        }
        return $new_articles;
    }

    static function setArticlesKey($articles) {
        foreach ($articles as $article) {
            if (isset($article->pivot) && $article->pivot->variant_id) {
                $article->key = $article->id . '-' . $article->pivot->variant_id;
            } else {
                $article->key = $article->id;
            }
        }
        return $articles;
    }

    static function setArticlesKeyAndVariant($articles) {
        foreach ($articles as $article) {
            if (isset($article->pivot) && $article->pivot->variant_id) {
                foreach ($article->variants as $variant) {
                    if ($variant->id == $article->pivot->variant_id) {
                        $article->variant = $variant;
                    }
                }
                $article->key = $article->id . '-' . $article->pivot->variant_id;
            } else {
                $article->key = $article->id;
            }
        }
        return $articles;
    }

    static function setArticlesVariants($articles) {
        foreach ($articles as $article) {
            if (isset($article->pivot) && !is_null($article->pivot->variant_id)) {
                $article->selected_variant = ArticleVariant::where('id', $article->pivot->variant_id)
                                                            ->with('article_property_values.article_property_type')
                                                            ->first();
            } 
        }
        return $articles;
    }

    // static function setArticlesRelationsFromPivot($articles) {
    //     $articles = Self::setArticlesColor($articles);
    //     $articles = Self::setArticlesSize($articles);
    //     return $articles;
    // }

    // static function setArticlesColor($articles) {
    //     $colors = Color::all();
    //     foreach ($articles as $article) {
    //         if (isset($article->pivot) && $article->pivot->color_id) {
    //             foreach ($colors as $color) {
    //                 if ($color->id == $article->pivot->color_id) {
    //                     $article->color = $color;
    //                 }
    //             }
    //         } 
    //     }
    //     return $articles;
    // }

    // static function setArticlesSize($articles) {
    //     $sizes = Size::all();
    //     foreach ($articles as $article) {
    //         if (isset($article->pivot) && $article->pivot->size_id) {
    //             foreach ($sizes as $size) {
    //                 if ($size->id == $article->pivot->size_id) {
    //                     $article->size = $size;
    //                 }
    //             }
    //         } 
    //     }
    //     return $articles;
    // }

    static function createArticle($article, $variant) {
        $new_article = new \stdClass();
        foreach ($article->getRelations() as $key => $value) {
            $new_article->{$key} = $value;
        }
        foreach ($article->getAttributes() as $key => $value) {
            if ($key == 'name') {
                $new_article->{$key} = $value . ' ' . $variant->description;
            } else {
                $new_article->{$key} = $value;
            }
        }
        $new_article->key = $article->id . '-' . $variant->id;
        $new_article->is_variant = true;
        $new_article->variant = $variant;
        return $new_article;
    }
}
