@component('mail::layout')

@slot('header')
@component('mail::header', ['url' => $commerce->online])
<img src="{{ $logo_url }}" class="logo" alt="{{ $commerce->company_name }} Logo">
@endcomponent
@endslot
<p>
	Has solicitado que te enviemos un código para recuperar tu contraseña.
</p>
<p>
	Aquí debajo te dejamos el código para que valides tu cuenta y puedas cambiar tu contraseña.
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
