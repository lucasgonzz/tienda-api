@extends('emails.orders.layout', [
	'titulo' => 'Tu código para recuperar la contraseña',
	'bajada' => 'Pediste cambiar la contraseña de tu cuenta en '.$company_name.'. Escribí este código en la página para seguir.',
	'logo_url' => $logo_url,
	'company_name' => $company_name,
])

@section('contenido')
	<!-- Codigo: caja destacada con el color de acento del comercio -->
	<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:{{ $accent_color }}; border-radius:12px;">
		<tr>
			<td align="center" style="padding:26px 16px;">
				<div style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif; font-size:12px; font-weight:600; letter-spacing:0.08em; text-transform:uppercase; color:{{ $text_color }}; opacity:0.85;">Tu código</div>
				<div style="margin-top:8px; font-family:'SFMono-Regular',Menlo,Consolas,'Courier New',monospace; font-size:44px; line-height:1.1; font-weight:700; letter-spacing:0.28em; padding-left:0.28em; color:{{ $text_color }};">{{ $code }}</div>
			</td>
		</tr>
	</table>

	<p style="margin:20px 0 0 0; font-size:15px; line-height:1.6; color:#374151;">
		El código vence en <strong style="color:#111827;">{{ $minutes }} minutos</strong> y sirve una sola vez. Si te equivocás varias veces seguidas, vas a tener que pedir uno nuevo.
	</p>

	<p style="margin:12px 0 0 0; font-size:14px; line-height:1.6; color:#6b7280;">
		Si no pediste cambiar tu contraseña, ignorá este mail: tu cuenta sigue como estaba y nadie puede cambiarla sin este código. No se lo pases a nadie.
	</p>
@endsection

@section('cta')
@endsection
