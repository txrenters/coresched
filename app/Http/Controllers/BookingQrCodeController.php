<?php

namespace App\Http\Controllers;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class BookingQrCodeController extends Controller
{
    /**
     * The width and height of the rendered code, in pixels.
     */
    protected int $size = 512;

    /**
     * Render a QR code for one of this app's public booking links.
     *
     * Only URLs under this app's /book/ pages are accepted, so the endpoint
     * cannot be used to stamp a QR code onto an arbitrary site.
     */
    public function __invoke(Request $request): Response
    {
        $validated = $request->validate([
            'url' => ['required', 'string', 'max:2048', function (string $attribute, string $value, \Closure $fail) {
                if (! Str::startsWith($value, url('book').'/')) {
                    $fail('Only booking links can be turned into a QR code.');
                }
            }],
            'name' => ['nullable', 'string', 'max:120'],
            'download' => ['nullable', 'boolean'],
        ]);

        $svg = (new Writer(new ImageRenderer(
            new RendererStyle($this->size, 2),
            new SvgImageBackEnd,
        )))->writeString($validated['url']);

        $response = response($svg, 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'private, max-age=86400',
        ]);

        if ($request->boolean('download')) {
            $filename = Str::slug($validated['name'] ?? 'booking-link') ?: 'booking-link';

            $response->headers->set('Content-Disposition', "attachment; filename=\"{$filename}-qr-code.svg\"");
        }

        return $response;
    }
}
