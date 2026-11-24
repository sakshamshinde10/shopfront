<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Shopfront') · Shopfront</title>
    <meta name="description" content="Turn the links and files a business already has into a website it can edit and publish, without inventing anything.">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-full bg-stone-50 text-stone-900 antialiased">
    <header class="border-b border-stone-200 bg-white/80 backdrop-blur">
        <div class="mx-auto flex max-w-6xl items-center justify-between px-4 py-3 sm:px-6">
            <a href="/" class="flex items-center gap-2 font-semibold tracking-tight">
                <span class="grid h-8 w-8 place-items-center rounded-lg bg-teal-700 text-white" aria-hidden="true">
                    <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l1.5-5h15L21 9"/><path d="M4 9v11h16V9"/><path d="M9 20v-6h6v6"/></svg>
                </span>
                Shopfront
            </a>
            @hasSection('nav')
                @yield('nav')
            @endif
        </div>
    </header>

    <main class="mx-auto max-w-6xl px-4 py-8 sm:px-6 sm:py-10">
        @yield('content')
    </main>

    <footer class="mx-auto max-w-6xl px-4 pb-10 text-xs text-stone-500 sm:px-6">
        Shopfront never invents facts. If it is not sure, it asks you.
    </footer>
</body>
</html>
