{{--
    $organization is set on booking emails (BookingMailPresenter::withSender)
    so the header carries the organization's own name. Account emails have no
    organization and are headed with the app's icon and name. The icon is the
    PNG touch icon on its own navy tile, so it reads on light and dark inboxes
    alike (most clients do not render SVG). The footer is always the app's
    own brand.
--}}
@props(['organization' => null])
<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="$organization['url'] ?? config('app.url')">
@if ($organization)
{{ $organization['name'] }}
@else
<img src="{{ asset('apple-touch-icon.png') }}" width="32" height="32" alt="" style="width: 32px; height: 32px; border: 0; border-radius: 7px; vertical-align: middle; margin-right: 8px;"><span style="vertical-align: middle;">{{ config('app.name') }}</span>
@endif
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
