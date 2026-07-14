<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f9fafb; border:1px solid #f3f4f6; border-radius:10px;">
	<tr>
		<td style="padding:16px 18px; font-size:14px; line-height:1.7; color:#374151;">
			<strong style="color:#111827;">Pedido N° {{ $data['order']->num }}</strong><br>
			<span style="color:#6b7280;">{{ $data['fecha'] }}</span>

			@if ($para_comercio)
				<div style="margin-top:12px;">
					<strong style="color:#111827;">Cliente:</strong> {{ $data['buyer_name'] }}<br>
					@if (!is_null($data['buyer_email']))
						<strong style="color:#111827;">Mail:</strong> {{ $data['buyer_email'] }}<br>
					@endif
					@if (!is_null($data['buyer_phone']))
						<strong style="color:#111827;">Telefono:</strong> {{ $data['buyer_phone'] }}<br>
					@endif
				</div>
			@endif

			<div style="margin-top:12px;">
				<strong style="color:#111827;">Entrega:</strong> {{ $data['entrega']['tipo'] }}<br>
				@if (!is_null($data['entrega']['detalle']))
					<span style="color:#6b7280;">{{ $data['entrega']['detalle'] }}</span><br>
				@endif
				@if (!is_null($data['fecha_entrega']))
					<strong style="color:#111827;">Fecha de entrega:</strong> {{ $data['fecha_entrega'] }}<br>
				@endif
				@if (!is_null($data['payment_method']))
					<strong style="color:#111827;">Medio de pago:</strong> {{ $data['payment_method'] }}
				@endif
			</div>

			@if (!empty($data['order']->description))
				<div style="margin-top:12px;">
					<strong style="color:#111827;">Nota del pedido:</strong><br>
					<span style="color:#6b7280;">{{ $data['order']->description }}</span>
				</div>
			@endif
		</td>
	</tr>
</table>
