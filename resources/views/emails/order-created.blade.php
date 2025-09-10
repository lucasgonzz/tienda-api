@component('mail::message')
# Nuevo Pedido

Has recibido un nuevo pedido de tu tienda de {{ $order->buyer->name }}.

@if (!is_null($order->user->default_version))
@component('mail::button', ['url' => $order->user->default_version . '/online/pedidos'])
Ir a PEDIDOS
@endcomponent
@endif


¡Que tengas un lindo dia!<br>
ComercioCity

@component('mail::footer')
© {{ date('Y') }} ComercioCity. Todos los derechos reservados.
@endcomponent
@endcomponent
