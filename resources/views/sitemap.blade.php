{!! '<?xml version="1.0" encoding="UTF-8"?>' !!}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
@foreach (\App\Http\Middleware\NoIndexExceptMarketing::INDEXABLE_ROUTES as $routeName)
    <url>
        <loc>{{ route($routeName) }}</loc>
    </url>
@endforeach
</urlset>
