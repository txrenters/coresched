@props(['url', 'logo' => null])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
@if ($logo)
<img src="{{ $logo }}" class="logo" alt="{{ trim($slot) }}" style="height: 64px; max-height: 64px; width: auto; max-width: 240px;">
@else
{!! $slot !!}
@endif
</a>
</td>
</tr>
