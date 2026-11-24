@extends('layouts.app')

@section('title', 'Page not found')

@section('content')
<div class="mx-auto max-w-md py-16 text-center">
    <p class="text-sm font-semibold uppercase tracking-widest text-teal-700">404</p>
    <h1 class="mt-2 font-display text-3xl font-semibold">We could not find that page</h1>
    <p class="mt-3 text-stone-600">The address may be wrong, or the website may not be published right now.</p>
    <a href="/" class="btn btn-primary mt-6">Go to the start page</a>
</div>
@endsection
