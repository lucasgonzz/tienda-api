@component('mail::message')
# Nuevo Pedido

Has recibido un nuevo pedido de tu tienda de {{ $order->buyer->name }}.

@component('mail::button', ['url' => 'https://comerciocity.com/online/pedidos'])
Ir a PEDIDOS
@endcomponent

Que tengas un lindo dia!<br>
ComercioCity
@endcomponent
