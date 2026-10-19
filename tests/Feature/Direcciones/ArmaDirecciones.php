<?php

namespace Tests\Feature\Direcciones;

use App\Buyer;
use App\BuyerAddress;
use App\User;
use Illuminate\Support\Str;

/**
 * Fixtures de los tests de direcciones del comprador (misión formas-envio-direcciones-maps,
 * 19/10/2026). Un comercio y compradores propios por test: todo se va con `DatabaseTransactions`.
 */
trait ArmaDirecciones
{
    /**
     * @return \App\User
     */
    protected function comercioDeDirecciones()
    {
        return User::create([
            'name'     => 'Comercio Direcciones Test',
            'email'    => 'direcciones-'.Str::random(10).'@test.local',
            'password' => bcrypt('secreto'),
            'status'   => 'commerce',
        ]);
    }

    /**
     * Un comprador con CUENTA: con contraseña (`Buyer::es_cuenta()`). Sin ella sería una ficha de
     * invitado, que no tiene libreta de direcciones.
     *
     * @param \App\User $comercio
     * @return \App\Buyer
     */
    protected function compradorConCuenta(User $comercio)
    {
        return Buyer::create([
            'name'     => 'Comprador Direcciones Test',
            'email'    => 'comprador-dir-'.Str::random(8).'@test.local',
            'phone'    => '3511234567',
            'password' => bcrypt('secreto'),
            'user_id'  => $comercio->id,
        ]);
    }

    /**
     * La ficha que deja un checkout de invitado: sin contraseña ni `provider_id`.
     * `BuyerController::login()` igual le abre el guard `buyer`.
     *
     * @param \App\User $comercio
     * @return \App\Buyer
     */
    protected function fichaDeInvitado(User $comercio)
    {
        return Buyer::create([
            'name'    => 'Invitado Direcciones Test',
            'email'   => 'invitado-dir-'.Str::random(8).'@test.local',
            'phone'   => '3511234567',
            'user_id' => $comercio->id,
        ]);
    }

    /**
     * Una dirección como la arma el buscador de Google del SPA.
     *
     * @param array $atributos
     * @return array
     */
    protected function direccionDeGoogle(array $atributos = [])
    {
        return array_merge([
            'street'            => 'Avenida Colón',
            'street_number'     => '123',
            'apartment'         => '4 B',
            'city'              => 'Córdoba',
            'province'          => 'Córdoba',
            'zip_code'          => 'X5000',
            'reference'         => 'portón negro',
            'formatted_address' => 'Av. Colón 123, X5000 Córdoba, Argentina',
            'lat'               => -31.4135,
            'lng'               => -64.1811,
            'place_id'          => 'ChIJ-prueba-123',
        ], $atributos);
    }

    /**
     * Una dirección guardada directo en la base (sin pasar por el endpoint).
     *
     * @param \App\Buyer $comprador
     * @param array $atributos
     * @return \App\BuyerAddress
     */
    protected function direccionGuardada(Buyer $comprador, array $atributos = [])
    {
        return BuyerAddress::create(array_merge($this->direccionDeGoogle(), [
            'buyer_id'   => $comprador->id,
            'user_id'    => $comprador->user_id,
            'is_default' => false,
        ], $atributos));
    }
}
