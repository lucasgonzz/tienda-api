@php
	use App\Http\Controllers\Helpers\OrderTotalsHelper;
@endphp

<table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:8px; border-collapse:collapse;">
	<thead>
		<tr>
			<th align="left" style="padding:0 0 8px 0; border-bottom:1px solid #e5e7eb; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.06em; color:#9ca3af;">Articulo</th>
			<th align="center" style="padding:0 0 8px 0; border-bottom:1px solid #e5e7eb; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.06em; color:#9ca3af;">Cant.</th>
			<th align="right" style="padding:0 0 8px 0; border-bottom:1px solid #e5e7eb; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.06em; color:#9ca3af;">Precio</th>
			<th align="right" style="padding:0 0 8px 0; border-bottom:1px solid #e5e7eb; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.06em; color:#9ca3af;">Subtotal</th>
		</tr>
	</thead>
	<tbody>
		@foreach ($totals['lines'] as $line)
		<tr>
			<td align="left" style="padding:12px 8px 12px 0; border-bottom:1px solid #f3f4f6; font-size:14px; line-height:1.5; color:#111827;">
				{{ $line['name'] }}
				@if (!is_null($line['variant']))
					<div style="font-size:12px; color:#6b7280; margin-top:2px;">{{ $line['variant'] }}</div>
				@endif
				@if (!empty($line['notes']))
					<div style="font-size:12px; color:#6b7280; margin-top:2px; font-style:italic;">Nota: {{ $line['notes'] }}</div>
				@endif
			</td>
			<td align="center" style="padding:12px 8px; border-bottom:1px solid #f3f4f6; font-size:14px; color:#374151; white-space:nowrap;">{{ $line['amount'] }}</td>
			<td align="right" style="padding:12px 8px; border-bottom:1px solid #f3f4f6; font-size:14px; color:#374151; white-space:nowrap;">{{ OrderTotalsHelper::money($line['unit_price']) }}</td>
			<td align="right" style="padding:12px 0 12px 8px; border-bottom:1px solid #f3f4f6; font-size:14px; color:#111827; white-space:nowrap;">{{ OrderTotalsHelper::money($line['line_total']) }}</td>
		</tr>
		@endforeach
	</tbody>
</table>

<table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:16px;">
	<tr>
		<td align="right" style="padding:4px 0; font-size:14px; color:#6b7280;">Subtotal</td>
		<td align="right" style="padding:4px 0 4px 24px; font-size:14px; color:#374151; white-space:nowrap;">{{ OrderTotalsHelper::money($totals['subtotal']) }}</td>
	</tr>
	@if (!is_null($totals['payment_method_amount']))
	<tr>
		<td align="right" style="padding:4px 0; font-size:14px; color:#6b7280;">{{ $totals['payment_method_label'] }}</td>
		<td align="right" style="padding:4px 0 4px 24px; font-size:14px; color:#374151; white-space:nowrap;">{{ OrderTotalsHelper::money($totals['payment_method_amount']) }}</td>
	</tr>
	@endif
	@if (!is_null($totals['cupon_amount']))
	<tr>
		<td align="right" style="padding:4px 0; font-size:14px; color:#6b7280;">{{ $totals['cupon_label'] }}</td>
		<td align="right" style="padding:4px 0 4px 24px; font-size:14px; color:#374151; white-space:nowrap;">{{ OrderTotalsHelper::money($totals['cupon_amount']) }}</td>
	</tr>
	@endif
	@if (!is_null($totals['delivery_amount']))
	<tr>
		<td align="right" style="padding:4px 0; font-size:14px; color:#6b7280;">{{ $totals['delivery_label'] }}</td>
		<td align="right" style="padding:4px 0 4px 24px; font-size:14px; color:#374151; white-space:nowrap;">{{ OrderTotalsHelper::money($totals['delivery_amount']) }}</td>
	</tr>
	@endif
	<tr>
		<td align="right" style="padding:14px 0 0 0; border-top:1px solid #e5e7eb; font-size:16px; font-weight:600; color:#111827;">Total</td>
		<td align="right" style="padding:14px 0 0 24px; border-top:1px solid #e5e7eb; font-size:18px; font-weight:700; color:{{ $accent_color }}; white-space:nowrap;">{{ OrderTotalsHelper::money($totals['total']) }}</td>
	</tr>
</table>
