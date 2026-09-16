<?php

namespace App\Http\Controllers\Helpers;

use App\ArticlePrice;
use App\ArticleVariant;
use App\Color;
use App\Http\Controllers\Helpers\ClientOfferHelper;
use App\Http\Controllers\Helpers\CommerceHelper;
use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\UserHelper;
use App\PriceType;
use App\Size;
use App\User;
use Illuminate\Support\Facades\Log;

class ArticleHelper
{

    static function set_promociones_vinoteca($promociones_vinoteca) {
        foreach ($promociones_vinoteca as $promocion_vinoteca) {
            $promocion_vinoteca->is_promocion_vinoteca = true;
        }
        return $promociones_vinoteca;
    }

    static function checkPriceTypes($articles) {
        
        $buyer = Auth('buyer')->user(); 
        
        $commerce_id = null;
        if (count($articles) >= 1) {
            $commerce_id = $articles[0]->user_id;
        }

        if (!is_null($commerce_id) && CommerceHelper::hasExtencion('lista_de_precios_por_rango_de_cantidad_vendida', null, $commerce_id)) {

            if (is_null($buyer) && !Self::anonimo_puede_ver_precios($commerce_id)) {

                /* El camino de rangos tambien respeta la visibilidad (D1 del chequeo del
                   24/8/2026): en el SPA este camino corre DENTRO de articlePriceEfectivo(),
                   detras del mismo puede_ver_precios() que espeja
                   anonimo_puede_ver_precios() — asi que con la tienda en modo restrictivo
                   el anonimo tampoco recibe los rangos (ni se pagan sus queries).

                   Los tramos NO se filtran por ocultar_al_publico cuando el anonimo SI ve
                   precios: aunque cada tramo referencia una lista (price_type_id), los rangos
                   son configuracion deliberada del comercio en el ABM de rangos por categoria
                   — no la eleccion implicita por position que motivo ese checkbox — y sacar un
                   tramo del medio dejaria la escala de cantidades con agujeros y cambiaria
                   precios de tiendas publicadas sin que Lucas lo haya dictado. */
                $articles = Self::esconder_precios_al_anonimo($articles);

            } else {

                $articles = Self::set_ranges($articles);
            }

        } else if (!is_null($buyer) && $buyer->user->use_archivos_de_intercambio && !is_null($buyer->comercio_city_client) && !is_null($buyer->comercio_city_client->price_type)) {

            $price_type_id = $buyer->comercio_city_client->price_type->id;

            foreach ($articles as $article) {
                
                $articlePrice = ArticlePrice::where('price_type_id', $price_type_id)
                                            ->where('provider_code', $article->provider_code) 
                                            ->first();
            
                $article->final_price = $articlePrice->price;
            }

        } else if (!is_null($buyer) && !is_null($buyer->comercio_city_client) && !is_null($buyer->comercio_city_client->price_type)) {
            // Caso 3: buyer logueado con lista de precios asignada — usar final_price del pivot
            $price_type_id = $buyer->comercio_city_client->price_type->id;
            foreach ($articles as $article) {
                if (!is_null($article)) {
                    // Buscar la lista del buyer entre las price_types cargadas del artículo
                    $matched = $article->price_types->firstWhere('id', $price_type_id);
                    if (!is_null($matched) && !is_null($matched->pivot->final_price)) {
                        $article->final_price = $matched->pivot->final_price;
                    }
                }
            }
        } else if (count($articles) >= 1 && !is_null($articles[0])) {
            /**
             * Caso 4: nadie con lista propia — visitantes sin login, y compradores logueados
             * sin vinculo a un Client del ERP (o con Client sin lista asignada).
             *
             * Para el LOGUEADO nada cambia: lista de `position` mas alta, como siempre
             * (contrato compatible hacia atras).
             *
             * Para el ANONIMO, desde el 24/8/2026 (decision de Lucas, cierre de la escalada
             * prompts/escaladas/20260812-1034-s5-para-elegir-que-lista-de-precios-ve-el-visitante-a.json
             * del repo de contexto lucasgonzz/claude-comerciocity):
             *
             *   (a) manda la configuracion de visibilidad que el SPA ya respeta en
             *       src/mixins/generals.js::puede_ver_precios() — ver anonimo_puede_ver_precios().
             *       Hasta hoy el backend mandaba los precios SIEMPRE y el ocultamiento era solo
             *       cosmetico: cualquiera con las devtools los leia igual.
             *   (b) una lista con `price_types.ocultar_al_publico` (el checkbox "Ocultar al
             *       publico" del ABM de listas del ERP) jamas se le muestra, aunque tenga la
             *       `position` mas alta: cae a la siguiente visible, y si el comercio tiene
             *       listas pero ninguna queda visible, el anonimo se queda sin precios.
             *
             * 🔴 Sigue valiendo lo verificado el 12/8/2026: `online_configuration->online_price_type`
             * NO es "que lista ve el anonimo" sino "QUIEN ve precios". La lista del anonimo la
             * sigue eligiendo el orderBy('position','DESC') de aca abajo. Fuentes del esquema
             * (la tienda comparte la base del ERP y este repo no tiene migraciones):
             *   - empresa-api/database/migrations/2023_04_12_162001_create_online_price_types_table.php
             *     → la tabla es (id, name, slug): catalogo global.
             *   - empresa-api/database/seeders/OnlinePriceTypeSeeder.php → sus tres filas:
             *     `all`, `only_registered`, `only_buyers_with_comerciocity_client`.
             *   - empresa-api/database/migrations/2022_09_05_173919_create_price_types_table.php
             *     → `ocultar_al_publico` es columna original de `price_types`.
             */

            $es_anonimo = is_null($buyer);

            if ($es_anonimo && !Self::anonimo_puede_ver_precios($commerce_id)) {

                /* (a) La tienda exige registro y la configuracion dice que el visitante sin
                   login no ve precios: no le viaja ninguno. */
                $articles = Self::esconder_precios_al_anonimo($articles);

            } else {

                $price_types = PriceType::where('user_id', $articles[0]->user_id)
                                        ->whereNotNull('position')
                                        ->orderBy('position', 'DESC')
                                        ->get();

                $el_comercio_tiene_listas = count($price_types) >= 1;

                if ($es_anonimo) {
                    /* (b) Las ocultas al publico no juegan para el anonimo. Loose a proposito:
                       NULL y 0 son "visible". */
                    $price_types = $price_types->filter(function ($price_type) {
                        return $price_type->ocultar_al_publico != 1;
                    })->values();
                }

                if (count($price_types) >= 1) {
                    // La primera es la de posición más alta (precio público más caro)
                    $public_price_type = $price_types->first();
                    foreach ($articles as $article) {
                        if (!is_null($article)) {
                            $matched = $article->price_types->firstWhere('id', $public_price_type->id);
                            if (!is_null($matched) && !is_null($matched->pivot->final_price)) {
                                $article->final_price = $matched->pivot->final_price;
                            }
                        }
                    }

                    if ($es_anonimo) {
                        /* (b) Y tampoco viajan los pivots de las ocultas en el payload: "jamas
                           se le muestra" incluye a las devtools. Sin riesgo para el SPA: no lee
                           article.price_types en ningun camino de este caso (cero usos en
                           tienda-spa/src, medido hoy; en el camino de rangos usa article.ranges,
                           que arma set_ranges en el caso 1). */
                        foreach ($articles as $article) {
                            if (!is_null($article) && $article->relationLoaded('price_types')) {
                                $article->setRelation('price_types', $article->price_types->filter(function ($price_type) {
                                    return $price_type->ocultar_al_publico != 1;
                                })->values());
                            }
                        }
                    }

                } else if ($es_anonimo && $el_comercio_tiene_listas) {

                    /* (b) Hay listas pero todas ocultas al publico: el anonimo queda sin
                       precios. Si el comercio directamente no tiene listas con position, en
                       cambio, no se toca nada y vale lo que traiga la columna final_price,
                       como siempre. */
                    $articles = Self::esconder_precios_al_anonimo($articles);
                }
            }
        }

        /*
         * La oferta personalizada va DESPUES de resolver el precio y es lo ultimo que pasa:
         * el porcentaje del contrato con empresa-api se aplica sobre el precio YA resuelto,
         * sea cual sea de los cuatro casos de arriba. Ver el docblock de
         * 2026_08_17_100200_create_client_offers_table.php en empresa-api.
         *
         * Es el unico enganche de toda la mision: colgandolo aca, los 12 llamadores de
         * checkPriceTypes (ArticleController x4, HomeController x6, CartHelper::getFullModel)
         * muestran la oferta sin tocarse ni uno.
         */
        $articles = ClientOfferHelper::aplicar($articles);

        return $articles;
    }

    /**
     * Memo por request de anonimo_puede_ver_precios(), por comercio: checkPriceTypes() corre
     * hasta cuatro veces en una misma respuesta de la home (featured, in_offer, novedades y
     * last_uploads) y la configuracion no cambia en el medio. Mismo criterio que la memoria
     * de ClientOfferHelper: cada query de mas se paga en todos los llamadores.
     *
     * @var array<int|string, bool>
     */
    private static $visibilidad_del_anonimo = [];

    /**
     * ¿La configuracion online del comercio deja ver precios a un visitante sin login?
     *
     * Espejo servidor de puede_ver_precios() de tienda-spa (src/mixins/generals.js) evaluado
     * para el anonimo, con los mismos cortes y en el mismo orden (decision de Lucas,
     * 24/8/2026):
     *
     *   1. `online_configurations.register_to_buy` falsy → la tienda no exige registro para
     *      comprar → los precios son visibles para cualquiera, sin importar online_price_type
     *      (el SPA corta igual, antes de mirar el slug).
     *   2. `online_price_type.slug` 'only_registered' u 'only_buyers_with_comerciocity_client'
     *      → el anonimo no ve precios.
     *   3. Cualquier otro caso (slug 'all', o configuracion incompleta) → visibles, que es el
     *      comportamiento historico.
     *
     * @param  int|string|null  $commerce_id
     * @return bool
     */
    static function anonimo_puede_ver_precios($commerce_id) {
        /* En consola (phpunit, tinker) se relee SIEMPRE, igual que las memorias de
           ClientOfferHelper: un test que cambie online_configurations sin acordarse del
           reset no puede quedar leyendo visibilidad vieja. En el request web la memo
           manda, que es donde las queries repetidas se pagan. */
        if (!array_key_exists($commerce_id, Self::$visibilidad_del_anonimo) || app()->runningInConsole()) {

            $puede_ver = true;

            $commerce = User::find($commerce_id);
            $configuration = is_null($commerce) ? null : $commerce->online_configuration;

            if (!is_null($configuration) && $configuration->register_to_buy && !is_null($configuration->online_price_type)) {
                $puede_ver = !in_array($configuration->online_price_type->slug, [
                    'only_registered',
                    'only_buyers_with_comerciocity_client',
                ]);
            }

            Self::$visibilidad_del_anonimo[$commerce_id] = $puede_ver;
        }

        return Self::$visibilidad_del_anonimo[$commerce_id];
    }

    /**
     * Los feature tests cambian la configuracion online entre requests del mismo proceso:
     * la memo de arriba tiene que poder olvidarse (mismo patron que
     * ClientOfferHelper::olvidarMemoria()).
     */
    static function olvidar_visibilidad_del_anonimo() {
        Self::$visibilidad_del_anonimo = [];
    }

    /**
     * Deja los articulos sin NINGUN precio para el visitante sin login: ni el resuelto, ni
     * los de las columnas (final_price, price y de paso cost, que tampoco tiene por que
     * viajar), ni los pivots de las listas, ni los rangos por cantidad. Se usa cuando la
     * configuracion online del comercio dice que el anonimo no ve precios, y cuando todas
     * las listas con position estan ocultas al publico.
     *
     * `ranges` queda como ARRAY VACIO y no sin setear: es la misma forma que set_ranges()
     * ya deja hoy en los articulos sin rangos configurados, asi que el SPA ya convive con
     * ella — el forEach de generals.js::articlePriceEfectivo() no itera (con undefined
     * tiraria TypeError si algun camino llegara), y PriceRanges.vue renderiza vacio.
     *
     * @param  mixed  $articles  Coleccion, paginador o array de articulos.
     * @return mixed  Los mismos articulos, pelados de precios.
     */
    static function esconder_precios_al_anonimo($articles) {
        foreach ($articles as $article) {
            if (!is_null($article)) {
                $article->final_price = null;
                $article->price = null;
                $article->cost = null;
                $article->ranges = [];
                if ($article->relationLoaded('price_types')) {
                    $article->setRelation('price_types', $article->price_types->take(0));
                }
                /* Los tramos por artículo (misión combos-y-rangos-de-precio) llevan un precio
                   unitario absoluto adentro: si se vacían `final_price` y `ranges` pero se dejan
                   estos, el precio que la tienda dice esconder viaja igual en el JSON y se lee con
                   las devtools. Se vacían por el mismo motivo y en el mismo lugar que `ranges`. */
                if ($article->relationLoaded('article_price_ranges')) {
                    $article->setRelation('article_price_ranges', $article->article_price_ranges->take(0));
                }
            }
        }
        return $articles;
    }

    static function set_ranges($articles) {

        foreach ($articles as $article) {
            $ranges = [];

            if (
                !is_null($article->sub_category)
                && count($article->sub_category->category_price_type_ranges) >= 1
            ) {

                foreach ($article->sub_category->category_price_type_ranges as $range) {

                    $article_price_type = $article->price_types->firstWhere('id', $range->price_type_id);

                    $_range = $range;
                    $_range->price = $article_price_type->pivot->price;
                    $ranges[] = $_range;
                }

            } else if (
                !is_null($article->category)
                && count($article->category->category_price_type_ranges) >= 1
            ) {
                
                foreach ($article->category->category_price_type_ranges as $range) {
                    
                    $article_price_type = $article->price_types->firstWhere('id', $range->price_type_id);

                    $_range = $range;
                    $_range->price = $article_price_type->pivot->price;
                    
                    if (is_null($range->sub_category_id)) {
                        $ranges[] = $_range;
                    }
                }

            }

            $article->ranges = $ranges;
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
