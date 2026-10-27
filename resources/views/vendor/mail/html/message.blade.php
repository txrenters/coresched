{{--
    $organization is set on booking emails (BookingMailPresenter::withSender)
    so the header carries the organization's own name. Account emails have no
    organization and are headed with the app's name. The footer is always the
    app's own brand.
--}}
@props(['organization' => null])
<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="$organization['url'] ?? config('app.url')">
{{ $organization['name'] ?? config('app.name') }}
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }} {{ config('app.name') }}. {{ __('All rights reserved.') }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
