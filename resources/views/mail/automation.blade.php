{{--
    The whole message is the organizer's own markdown, so their bold, links and
    lists survive. It is injected raw on purpose: the mail markdown converter
    escapes literal HTML, so what they typed is what the reader gets, and a
    pasted <script> stays visible text rather than markup.
--}}
@component('mail::message', ['organization' => $organization ?? null])
{!! $body !!}
@endcomponent
