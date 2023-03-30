@component('mail::layout')

@slot('header')
@component('mail::header', ['url' => $commerce->online])
<img src="{{ $logo_url }}" class="logo" alt="{{ $commerce->company_name }} Logo">
@endcomponent
@endslot
<p>
	Hola {{ $buyer->name }}, gracias por registrarte en nuestra plataforma!
</p>
<p>
	Aquí debajo te dejamos tu código de verificación para que completes tu registro.
</p>

@component('mail::panel')
{{ $code }}
@endcomponent

@slot('footer')
@component('mail::footer')
© {{ date('Y') }} ComercioCity
@endcomponent
@endslot

@endcomponent
