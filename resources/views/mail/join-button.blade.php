{{--
    Mirrors the markup of the mail::button component so the theme styles it the
    same, but as a plain view: MailMessage allows only one action, and the host's
    copy already spends it on "View booking". No blank lines -- the markdown
    converter ends an HTML block at the first one.
--}}
<table class="action" align="center" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="center">
<table border="0" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td>
<a href="{{ $url }}" class="button button-primary" target="_blank" rel="noopener">{{ $label }}</a>
</td>
</tr>
</table>
</td>
</tr>
</table>
