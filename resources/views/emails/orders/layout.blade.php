<!DOCTYPE html>
<html lang="es">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>{{ $titulo }}</title>
</head>
<body style="margin:0; padding:0; background-color:#f4f4f5; -webkit-font-smoothing:antialiased;">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f4f4f5;">
	<tr>
		<td align="center" style="padding:32px 16px;">

			<table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px; width:100%; background-color:#ffffff; border:1px solid #e5e7eb; border-radius:14px; overflow:hidden;">

				<!-- Logo -->
				<tr>
					<td align="center" style="padding:32px 32px 8px 32px;">
						@if (!is_null($logo_url))
							<img src="{{ $logo_url }}" alt="{{ $company_name }}" style="max-height:52px; max-width:220px; display:block; border:0;">
						@else
							<div style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif; font-size:20px; font-weight:600; color:#111827;">{{ $company_name }}</div>
						@endif
					</td>
				</tr>

				<!-- Titulo -->
				<tr>
					<td style="padding:16px 32px 0 32px; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;">
						<h1 style="margin:0; font-size:24px; line-height:1.3; font-weight:600; color:#111827; letter-spacing:-0.02em;">{{ $titulo }}</h1>
						@if (!empty($bajada))
							<p style="margin:8px 0 0 0; font-size:15px; line-height:1.6; color:#6b7280;">{{ $bajada }}</p>
						@endif
					</td>
				</tr>

				<!-- Contenido -->
				<tr>
					<td style="padding:24px 32px 0 32px; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;">
						@yield('contenido')
					</td>
				</tr>

				<!-- CTA -->
				<tr>
					<td align="center" style="padding:28px 32px 36px 32px;">
						@yield('cta')
					</td>
				</tr>

			</table>

			<!-- Footer -->
			<table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px; width:100%;">
				<tr>
					<td align="center" style="padding:20px 16px 0 16px; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif; font-size:12px; line-height:1.6; color:#9ca3af;">
						{{ $company_name }} &middot; {{ date('Y') }}<br>
						Enviado automaticamente por ComercioCity
					</td>
				</tr>
			</table>

		</td>
	</tr>
</table>
</body>
</html>
