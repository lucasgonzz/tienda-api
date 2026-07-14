@extends('emails.orders.layout', [
	'titulo' => 'Entro un pedido nuevo',
	'bajada' => $data['buyer_name'].' acaba de hacer un pedido en tu tienda online.',
])

@section('contenido')
	@include('emails.orders.partials.datos', ['data' => $data, 'para_comercio' => true])
	@include('emails.orders.partials.detalle', ['totals' => $data['totals'], 'accent_color' => $data['accent_color']])
@endsection

@section('cta')
	@if (!is_null($data['erp_orders_url']))
		<a href="{{ $data['erp_orders_url'] }}" style="display:inline-block; padding:13px 28px; background-color:{{ $data['accent_color'] }}; color:#ffffff; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif; font-size:15px; font-weight:600; text-decoration:none; border-radius:8px;">Ver el pedido en el sistema</a>
	@endif
@endsection
