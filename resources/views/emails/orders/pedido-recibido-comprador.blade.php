@extends('emails.orders.layout', [
	'titulo' => 'Recibimos tu pedido',
	'bajada' => 'Gracias por tu compra, '.$data['buyer_name'].'. Te dejamos el detalle de lo que pediste. Te vamos a avisar cuando lo confirmemos.',
	'logo_url' => $data['logo_url'],
	'company_name' => $data['company_name'],
])

@section('contenido')
	@include('emails.orders.partials.datos', ['data' => $data, 'para_comercio' => false])
	@include('emails.orders.partials.detalle', ['totals' => $data['totals'], 'accent_color' => $data['accent_color']])
@endsection

@section('cta')
	@if (!is_null($data['store_url']))
		<a href="{{ $data['store_url'] }}" style="display:inline-block; padding:13px 28px; background-color:{{ $data['accent_color'] }}; color:#ffffff; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif; font-size:15px; font-weight:600; text-decoration:none; border-radius:8px;">Volver a la tienda</a>
	@endif
@endsection
