<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class OnlineConfiguration extends Model
{
    protected $guarded = [];

    /**
     * 🔴 CRITICO: CommerceController@commerce es un endpoint PUBLICO (sin auth) que devuelve este
     * modelo entero a cualquier visitante de la tienda. Sin este $hidden, la configuracion SMTP del
     * comercio (host, usuario, y el blob encriptado de la contraseña) viaja en esa respuesta.
     * $hidden solo afecta la serializacion a JSON: el acceso desde PHP (ClientMailConfigHelper) sigue
     * funcionando normal.
     */
    protected $hidden = [
        'mail_enabled',
        'mail_host',
        'mail_port',
        'mail_encryption',
        'mail_username',
        'mail_password',
        'mail_from_address',
        'mail_from_name',
        'mail_notificacion_pedidos',
        // Mismo motivo que las columnas de mail de arriba (prompt 590, grupo 164): este modelo
        // viaja entero en la respuesta PUBLICA de CommerceController@commerce. El SPA solo
        // necesita saber si el boton de Google se muestra (ver
        // configuration.show_google_login, calculado en el controller), no las credenciales.
        'google_client_id',
        'google_client_secret',
        /*
         * Credenciales de Mercado Pago y Zippin (mision de seguridad, 15/8/2026). Cada comercio
         * conecta su propia cuenta por OAuth desde el ERP; empresa-api las guarda cifradas (cast
         * 'encrypted' en su app/Models/OnlineConfiguration.php) y las oculta. Este repo no tenia
         * ni el cast ni el $hidden, asi que serializaba el ciphertext crudo en la respuesta
         * PUBLICA de CommerceController@commerce. Medido con centinelas: los cinco salian.
         *
         * Que viajen cifrados no los vuelve inofensivos: es material secreto publicado en una
         * URL sin autenticacion.
         *
         * Verificado antes de ocultarlas: ni tienda-api ni tienda-spa leen ninguna de estas
         * columnas (cero coincidencias en los dos repos). La public_key de Mercado Pago que usa
         * el SPA para el brick de pago sale de payment_methods.public_key
         * (components/payment/components/payment-method/CardPaymentMethod.vue:66), no de aca.
         */
        'mp_access_token',
        'mp_refresh_token',
        'mp_user_id',
        'mp_public_key',
        'zippin_access_token',
        'zippin_refresh_token',
        'zippin_account_id',
    ];

    protected $casts = [
        'mail_enabled' => 'boolean',
        'mail_port' => 'integer',
        // Mismo cast que en empresa-api: la contraseña se guarda encriptada con la APP_KEY y se
        // desencripta al leer el atributo.
        'mail_password' => 'encrypted',
        'notificar_pedido_al_negocio' => 'boolean',
        'notificar_pedido_al_cliente' => 'boolean',
        'avisar_ingreso_stock_por_mail' => 'boolean',
        // Login con Google de la tienda online (prompt 590, grupo 164).
        'google_login_enabled' => 'boolean',
    ];

    function online_price_type() {
        return $this->belongsTo(OnlinePriceType::class);
    }

    function online_template() {
        return $this->belongsTo(OnlineTemplate::class);
    }
}
