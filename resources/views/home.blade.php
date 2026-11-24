@extends('layouts.app')

@section('title', 'Build a website from what you already have')

@section('content')
<div class="grid items-start gap-10 lg:grid-cols-5" x-data="home" x-cloak>
    <section class="lg:col-span-2 lg:pt-6">
        <h1 class="font-display text-4xl font-semibold leading-tight tracking-tight sm:text-5xl">
            A website from what you already have.
        </h1>
        <p class="mt-4 text-lg text-stone-600">
            Give us your business name and anything you have: a link, a price list, photos. We read it, ask you about anything we are not sure of, and write your page.
        </p>

        <ul class="mt-6 space-y-3 text-sm text-stone-700">
            <li class="flex gap-3"><span class="mt-0.5 text-teal-700">&#10003;</span><span><strong>Nothing made up.</strong> Every detail on your page comes from something you gave us.</span></li>
            <li class="flex gap-3"><span class="mt-0.5 text-teal-700">&#10003;</span><span><strong>We ask when two sources disagree</strong>, or when several businesses share your name.</span></li>
            <li class="flex gap-3"><span class="mt-0.5 text-teal-700">&#10003;</span><span><strong>No account.</strong> You get a private link to edit your site later.</span></li>
        </ul>

        <template x-if="recent.length > 0">
            <div class="mt-8">
                <h2 class="text-sm font-semibold text-stone-700">Your sites on this device</h2>
                <ul class="mt-2 divide-y divide-stone-200 rounded-xl border border-stone-200 bg-white">
                    <template x-for="site in recent" :key="site.id">
                        <li>
                            <a :href="`/sites/${site.id}`" class="flex items-center justify-between px-4 py-3 text-sm hover:bg-stone-50">
                                <span class="font-medium" x-text="site.name || `Site ${site.id}`"></span>
                                <span class="text-stone-500" x-text="site.city"></span>
                            </a>
                        </li>
                    </template>
                </ul>
            </div>
        </template>
    </section>

    <form class="card space-y-5 lg:col-span-3" @submit.prevent="submit" novalidate>
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="label" for="name">Business name</label>
                <input id="name" class="field" x-model="name" required maxlength="150" placeholder="Sharma Bakery" autocomplete="organization"
                       :aria-invalid="!!firstError('business_name')">
                <p class="error-text" x-show="firstError('business_name')" x-text="firstError('business_name')"></p>
            </div>
            <div>
                <label class="label" for="city">City</label>
                <input id="city" class="field" x-model="city" maxlength="100" placeholder="Pune" autocomplete="address-level2">
                <p class="hint">Helps us tell you apart from businesses with the same name.</p>
            </div>
        </div>

        <div>
            <span class="label">Links</span>
            <p class="hint mb-2">Your website, Instagram, Facebook, a Google listing... Anything we cannot read, we will ask you about instead.</p>
            <div class="space-y-2">
                <template x-for="(item, index) in links" :key="index">
                    <div>
                        <div class="flex gap-2">
                            <input class="field" type="url" x-model="links[index]" placeholder="https://" :aria-label="`Link ${index + 1}`">
                            <button type="button" class="btn-link shrink-0" @click="removeLink(index)" :aria-label="`Remove link ${index + 1}`">Remove</button>
                        </div>
                        <p class="error-text" x-show="firstError(`links.${index}`)" x-text="firstError(`links.${index}`)"></p>
                    </div>
                </template>
            </div>
            <button type="button" class="btn-link mt-2" @click="addLink" x-show="links.length < 10">+ Add another link</button>
        </div>

        <div>
            <label class="label" for="note">Anything else we should know</label>
            <textarea id="note" class="field" rows="4" x-model="note" maxlength="20000"
                      placeholder="Opening hours, phone number, what you sell, prices..."></textarea>
            <p class="hint">Write it the way you would tell a customer. We only use what you actually say.</p>
            <p class="error-text" x-show="firstError('note')" x-text="firstError('note')"></p>
        </div>

        <div>
            <label class="label" for="files">Files and photos</label>
            <input id="files" type="file" multiple accept=".pdf,.jpg,.jpeg,.png,.webp,.txt" @change="pickFiles"
                   class="block w-full text-sm text-stone-600 file:mr-3 file:rounded-lg file:border-0 file:bg-stone-100 file:px-3 file:py-2 file:text-sm file:font-medium hover:file:bg-stone-200">
            <p class="hint">PDF, JPG, PNG, WEBP or TXT. Up to 10 files, 10 MB each. A menu or price list photo works too.</p>
        </div>

        <div class="flex flex-wrap items-center gap-3 border-t border-stone-100 pt-5">
            <button type="submit" class="btn btn-primary" :disabled="busy || !name.trim()">
                <span x-show="!busy">Create my website</span>
                <span x-show="busy">Creating…</span>
            </button>
            <p class="text-sm text-red-700" role="alert" x-show="message" x-text="message"></p>
        </div>
    </form>
</div>
@endsection
