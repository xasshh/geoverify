<x-mail::message>
# {{ $heading }}

@foreach ($lines as $line)
{{ $line }}

@endforeach
<x-mail::button :url="$url">
{{ $buttonLabel }}
</x-mail::button>

<small>{{ $footnote }}</small>

<small>If the button does not work, copy this link into your browser: {{ $url }}</small>
</x-mail::message>
