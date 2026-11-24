<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $site->business_name }}@if($site->city) · {{ $site->city }}@endif</title>
    <meta name="description" content="{{ $description }}">
    <meta property="og:title" content="{{ $site->business_name }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:type" content="website">
    @if($photos->isNotEmpty())<meta property="og:image" content="{{ $photos->first() }}">@endif
    @vite(['resources/css/app.css'])

    {{-- Search engines read this. It lists only details the owner confirmed. --}}
    <script type="application/ld+json">{!! json_encode(array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'LocalBusiness',
        'name' => $site->business_name,
        'description' => $description,
        'telephone' => $contact['phone'] ?? null,
        'email' => $email,
        'address' => $contact['address'] ?? null,
        'openingHours' => $contact['hours'] ?? null,
        'sameAs' => $social->all() ?: null,
        'image' => $photos->all() ?: null,
    ]), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
</head>
<body class="bg-stone-50 text-stone-900 antialiased">
    <header class="bg-teal-800 text-white">
        <div class="mx-auto max-w-4xl px-5 py-16 sm:py-24">
            @if(! empty($hero['city']))
                <p class="text-sm font-medium uppercase tracking-widest text-teal-200">{{ $hero['city'] }}</p>
            @endif
            <h1 class="mt-2 font-display text-4xl font-semibold leading-tight tracking-tight sm:text-6xl">{{ $hero['title'] ?? $site->business_name }}</h1>
            @if(! empty($hero['tagline']))
                <p class="mt-4 max-w-2xl text-lg text-teal-50 sm:text-xl">{{ $hero['tagline'] }}</p>
            @endif
            @if($tel)
                <a href="tel:{{ $tel }}" class="mt-8 inline-flex rounded-lg bg-white px-5 py-3 text-sm font-semibold text-teal-900 hover:bg-teal-50">Call {{ $contact['phone'] }}</a>
            @endif
        </div>
    </header>

    <main class="mx-auto max-w-4xl space-y-14 px-5 py-14">
        @if(count($about) > 0)
            <section aria-labelledby="about">
                <h2 id="about" class="font-display text-2xl font-semibold">About us</h2>
                <div class="mt-4 space-y-4 text-lg leading-relaxed text-stone-700">
                    @foreach($about as $paragraph)
                        <p>{{ $paragraph }}</p>
                    @endforeach
                </div>
            </section>
        @endif

        @if(! empty($services['items']) || ! empty($services['prices']))
            <section aria-labelledby="services">
                <h2 id="services" class="font-display text-2xl font-semibold">What we offer</h2>
                @if(! empty($services['items']))
                    <ul class="mt-5 grid gap-4 sm:grid-cols-2">
                        @foreach($services['items'] as $item)
                            <li class="rounded-xl border border-stone-200 bg-white p-5">
                                <p class="font-semibold">{{ $item['name'] }}</p>
                                @if(! empty($item['description']))
                                    <p class="mt-1 text-stone-600">{{ $item['description'] }}</p>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
                @if(! empty($services['prices']))
                    <h3 class="mt-8 text-sm font-semibold uppercase tracking-wide text-stone-500">Prices</h3>
                    <ul class="mt-2 divide-y divide-stone-200 rounded-xl border border-stone-200 bg-white">
                        @foreach($services['prices'] as $price)
                            <li class="px-5 py-3">{{ $price }}</li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endif

        @if($photos->isNotEmpty())
            <section aria-labelledby="photos">
                <h2 id="photos" class="font-display text-2xl font-semibold">Photos</h2>
                <div class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-3">
                    @foreach($photos as $photo)
                        <img src="{{ $photo }}" alt="{{ $site->business_name }}, photo {{ $loop->iteration }}" loading="lazy"
                             class="aspect-square w-full rounded-xl object-cover">
                    @endforeach
                </div>
            </section>
        @endif

        @if(! empty($contact))
            <section aria-labelledby="contact" class="rounded-2xl border border-stone-200 bg-white p-6 sm:p-8">
                <h2 id="contact" class="font-display text-2xl font-semibold">Find us</h2>
                <dl class="mt-5 grid gap-x-6 gap-y-3 sm:grid-cols-[8rem_1fr]">
                    @if(! empty($contact['address']))
                        <dt class="text-stone-500">Address</dt><dd>{{ $contact['address'] }}</dd>
                    @endif
                    @if(! empty($contact['hours']))
                        <dt class="text-stone-500">Opening hours</dt><dd>{{ $contact['hours'] }}</dd>
                    @endif
                    @if($tel)
                        <dt class="text-stone-500">Phone</dt><dd><a class="font-medium text-teal-800 underline" href="tel:{{ $tel }}">{{ $contact['phone'] }}</a></dd>
                    @endif
                    @if($email)
                        <dt class="text-stone-500">Email</dt><dd><a class="font-medium text-teal-800 underline" href="mailto:{{ $email }}">{{ $email }}</a></dd>
                    @endif
                    @if($social->isNotEmpty())
                        <dt class="text-stone-500">Online</dt>
                        <dd class="space-y-1">
                            @foreach($social as $url)
                                <a class="block break-all font-medium text-teal-800 underline" href="{{ $url }}" rel="noopener nofollow noreferrer" target="_blank">{{ parse_url($url, PHP_URL_HOST) }}</a>
                            @endforeach
                        </dd>
                    @endif
                </dl>
            </section>
        @endif
    </main>

    <footer class="border-t border-stone-200 py-8 text-center text-sm text-stone-500">
        {{ $site->business_name }} · Made with Shopfront
    </footer>
</body>
</html>
