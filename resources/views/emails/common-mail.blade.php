@component('mail::message')

{{-- Header personalizado con logo --}}
@slot('header')
@component('mail::header', ['url' => config('app.url')])
<img src="{{ $logoUrl }}" alt="Logo" style="height: 50px;">
@endcomponent
@endslot

{{-- Contenido dinámico del mensaje --}}
@foreach ($body as $section)
@if(isset($section['title']))

### {{ $section['title'] }}

@endif
@if(isset($section['content']))
{{ $section['content'] }}

@endif
@endforeach

{{-- Footer personalizado --}}
@slot('footer')
@component('mail::footer')
<span style="font-size: 12px; color: #888;">
{{ $footer ?? '© ' . date('Y') . ' comerciocity. Todos los derechos reservados.' }}
</span>
@endcomponent
@endslot

@endcomponent
