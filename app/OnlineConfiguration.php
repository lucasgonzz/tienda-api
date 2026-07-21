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
