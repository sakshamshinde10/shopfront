@extends('layouts.app')

@section('title', 'Your website')

@section('nav')
    <a href="/" class="btn-link">New site</a>
@endsection

@section('content')
<div x-data="workspace({{ $id }})" x-cloak>
    <p class="text-stone-500" x-show="loading">Loading your site…</p>

    {{-- No key for this site in this browser --}}
    <div class="card mx-auto max-w-lg text-center" x-show="!loading && !token">
        <h1 class="text-xl font-semibold">We cannot open this site here</h1>
        <p class="mt-2 text-stone-600" x-text="error || 'Sites are opened with a private key that is saved in the browser you created them in. Use the private edit link you copied, or start a new site.'"></p>
        <a href="/" class="btn btn-primary mt-5">Start a new site</a>
    </div>

    <div x-show="token && site" class="space-y-6">
        {{-- Title bar --}}
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="font-display text-3xl font-semibold tracking-tight" x-text="site?.business_name"></h1>
                <p class="mt-1 flex flex-wrap items-center gap-2 text-sm text-stone-600">
                    <span x-show="site?.city" x-text="site?.city"></span>
                    <span class="pill" :class="statusClass" x-text="statusLabel"></span>
                </p>
            </div>
            <button type="button" class="btn btn-quiet" @click="copy('edit', editLink)">
                <span x-show="copied !== 'edit'">Copy private edit link</span>
                <span x-show="copied === 'edit'">Copied</span>
            </button>
        </div>

        {{-- Messages --}}
        <p class="rounded-lg bg-sky-50 px-4 py-3 text-sm text-sky-900" x-show="flash" x-text="flash"></p>
        <p class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800" role="alert" x-show="error && token" x-text="error"></p>
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg px-4 py-3 text-sm"
             :class="site?.status === 'failed' ? 'bg-red-50 text-red-800' : 'bg-amber-50 text-amber-900'"
             x-show="site?.notice" role="status">
            <span x-text="site?.notice"></span>
            <button type="button" class="btn btn-quiet" x-show="site?.status === 'failed'" @click="build" :disabled="isBusy('build')">Try again</button>
        </div>

        <div class="grid items-start gap-6 lg:grid-cols-5">
            {{-- LEFT: questions, then what we know --}}
            <div class="space-y-6 lg:col-span-2">

                <section class="card border-amber-300 bg-amber-50/40" x-show="(site?.questions ?? []).length > 0" aria-labelledby="questions-title">
                    <h2 id="questions-title" class="text-lg font-semibold">We need your help</h2>
                    <p class="mt-1 text-sm text-stone-600" x-show="blocking.length > 0">We will not guess. Please answer these so we can write your page.</p>

                    <div class="mt-4 space-y-5">
                        <template x-for="question in site.questions" :key="question.id">
                            <div class="rounded-xl border border-stone-200 bg-white p-4">
                                <p class="text-sm font-medium" x-text="question.prompt"></p>
                                <span class="pill mt-2 bg-amber-100 text-amber-800" x-show="question.blocking">Needed to continue</span>

                                <div class="mt-3 space-y-2" x-show="question.options">
                                    <template x-for="option in (question.options ?? [])" :key="option.value">
                                        <button type="button" class="btn btn-quiet w-full justify-start text-left" @click="answer(question, option.value)"
                                                :disabled="isBusy('answer' + question.id)" x-text="option.label"></button>
                                    </template>
                                </div>

                                <form class="mt-3 flex gap-2" @submit.prevent="answer(question, answers[question.id])"
                                      x-show="question.kind !== 'pick_business'">
                                    <input class="field" x-model="answers[question.id]" maxlength="300"
                                           :placeholder="question.kind === 'conflict' ? 'Or type the correct one' : 'Your answer'"
                                           :aria-label="question.prompt">
                                    <button type="submit" class="btn btn-primary shrink-0" :disabled="isBusy('answer' + question.id)">Save</button>
                                </form>
                                <button type="button" class="btn-link mt-2" x-show="question.kind === 'missing'" @click="answer(question, 'skip')">Skip this</button>
                                <p class="error-text" x-show="answerErrors[question.id]" x-text="answerErrors[question.id]"></p>
                            </div>
                        </template>
                    </div>
                </section>

                <section class="card" aria-labelledby="sources-title">
                    <h2 id="sources-title" class="text-lg font-semibold">What we know about your business</h2>
                    <p class="mt-1 text-sm text-stone-600">Everything on your page comes from these. Add more at any time, then build again.</p>

                    <ul class="mt-4 divide-y divide-stone-100">
                        <template x-for="source in site.sources" :key="source.id">
                            <li class="py-3">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="flex items-center gap-2 text-sm">
                                            <span class="shrink-0 rounded bg-stone-100 px-1.5 py-0.5 text-xs text-stone-600" x-text="kindLabel(source)"></span>
                                            <span class="truncate font-medium" x-text="source.label || source.url"></span>
                                        </p>
                                        <p class="mt-1.5 flex flex-wrap items-center gap-2">
                                            <span class="pill" :class="sourceState(source)[1]" x-text="sourceState(source)[0]"></span>
                                        </p>
                                        <p class="mt-1.5 text-sm text-stone-600" x-show="source.message" x-text="source.message"></p>
                                        <p class="mt-1.5 rounded bg-amber-50 px-2 py-1 text-sm text-amber-900" x-show="source.warning" x-text="source.warning"></p>
                                    </div>
                                    <button type="button" class="btn-danger shrink-0" @click="removeSource(source)" :disabled="isBusy('remove' + source.id)">Remove</button>
                                </div>
                            </li>
                        </template>
                    </ul>
                    <p class="text-sm text-stone-500" x-show="site.sources.length === 0">Nothing yet. Add a link, a note or a file below.</p>

                    <div class="mt-4 space-y-4 border-t border-stone-100 pt-4">
                        <form class="flex gap-2" @submit.prevent="addLink">
                            <input class="field" type="url" x-model="link" placeholder="Add a link (https://…)" aria-label="Add a link">
                            <button type="submit" class="btn btn-quiet shrink-0" :disabled="isBusy('addLink')">Add</button>
                        </form>

                        <form @submit.prevent="addNote">
                            <textarea class="field" rows="3" x-model="note" placeholder="Or type something we should know: phone number, hours, what you sell…" aria-label="Add a note"></textarea>
                            <button type="submit" class="btn btn-quiet mt-2" :disabled="isBusy('addNote')">Add note</button>
                        </form>

                        <div>
                            <label class="label" for="more-files">Add files or photos</label>
                            <input id="more-files" type="file" multiple accept=".pdf,.jpg,.jpeg,.png,.webp,.txt" @change="addFiles"
                                   class="block w-full text-sm text-stone-600 file:mr-3 file:rounded-lg file:border-0 file:bg-stone-100 file:px-3 file:py-2 file:text-sm file:font-medium hover:file:bg-stone-200">
                        </div>

                        <ul class="space-y-1" x-show="addErrors.length > 0" role="alert">
                            <template x-for="message in addErrors"><li class="error-text" x-text="message"></li></template>
                        </ul>
                    </div>
                </section>
            </div>

            {{-- RIGHT: build, publish, the page --}}
            <div class="space-y-6 lg:col-span-3">
                <section class="card" aria-labelledby="page-title">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h2 id="page-title" class="text-lg font-semibold">Your page</h2>
                            <p class="mt-1 text-sm text-stone-600" x-show="!working && !hasSections && site?.status !== 'needs_input'">
                                Press build and we will read your sources and write the page. This can take a minute.
                            </p>
                            <p class="mt-1 flex items-center gap-2 text-sm text-sky-800" x-show="working" role="status">
                                <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" opacity=".25"/><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                                <span x-text="site?.pending_sources > 0 ? 'Reading your sources…' : 'Writing your page… this can take a minute.'"></span>
                            </p>
                        </div>
                        <button type="button" class="btn btn-primary" @click="build" x-show="site?.status !== 'published'"
                                :disabled="!canBuild || isBusy('build')" x-text="buildLabel"></button>
                    </div>

                    {{-- Publish --}}
                    <div class="mt-5 border-t border-stone-100 pt-5" x-show="site?.status === 'ready' || site?.status === 'published'">
                        <div x-show="site?.status === 'ready'" class="flex flex-wrap items-center justify-between gap-3">
                            <p class="text-sm text-stone-600">Happy with it? Put it online at its own address.</p>
                            <button type="button" class="btn btn-primary" @click="publish" :disabled="isBusy('publish')">Publish</button>
                        </div>

                        <div x-show="site?.status === 'published'" class="space-y-3">
                            <p class="text-sm font-medium text-emerald-800">Your site is live.</p>
                            <div class="flex flex-wrap items-center gap-2">
                                <a :href="site?.public_url" target="_blank" rel="noopener" class="break-all text-sm font-medium text-teal-800 underline" x-text="site?.public_url"></a>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <a :href="site?.public_url" target="_blank" rel="noopener" class="btn btn-primary">Open</a>
                                <button type="button" class="btn btn-quiet" @click="copy('public', site.public_url)">
                                    <span x-show="copied !== 'public'">Copy link</span><span x-show="copied === 'public'">Copied</span>
                                </button>
                                <button type="button" class="btn btn-quiet" @click="unpublish" :disabled="isBusy('publish')">Take offline</button>
                            </div>
                        </div>
                    </div>
                </section>

                {{-- The sections --}}
                <template x-for="key in keys" :key="key">
                    <section class="card" x-show="hasSections || editing === key" :aria-label="titles[key]">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <h3 class="flex items-center gap-2 font-semibold">
                                <span x-text="titles[key]"></span>
                                <span class="pill bg-stone-100 text-stone-600" x-show="edited(key)">Edited by you</span>
                            </h3>
                            <div class="flex gap-1" x-show="editing !== key">
                                <button type="button" class="btn-link" @click="startEditing(key)" x-text="content(key) ? 'Edit' : 'Add'"></button>
                                <button type="button" class="btn-link" @click="regenerate(key)" :disabled="working"
                                        x-text="site?.regenerating === key ? 'Writing…' : (key === 'contact' ? 'Rebuild' : 'Regenerate')"></button>
                            </div>
                        </div>
                        <p class="mt-2 rounded bg-amber-50 px-3 py-2 text-sm text-amber-900" x-show="sectionNotes[key]" x-text="sectionNotes[key]" role="alert"></p>

                        {{-- Read view --}}
                        <div class="mt-3" x-show="editing !== key">
                            <p class="text-sm text-stone-500" x-show="!content(key)">Nothing here yet.</p>

                            <div x-show="key === 'hero' && content('hero')">
                                <p class="font-display text-2xl font-semibold" x-text="content('hero')?.title"></p>
                                <p class="mt-1 text-stone-700" x-show="content('hero')?.tagline" x-text="content('hero')?.tagline"></p>
                                <p class="mt-1 text-sm text-stone-500" x-show="content('hero')?.city" x-text="content('hero')?.city"></p>
                                <p class="mt-1 text-sm text-stone-500" x-show="content('hero') && !content('hero').tagline">No tagline: we had no facts to base one on.</p>
                            </div>

                            <div class="space-y-2" x-show="key === 'about' && content('about')">
                                <template x-for="paragraph in (content('about')?.paragraphs ?? [])"><p class="text-stone-800" x-text="paragraph"></p></template>
                            </div>

                            <div x-show="key === 'services' && content('services')">
                                <ul class="space-y-2">
                                    <template x-for="item in (content('services')?.items ?? [])">
                                        <li><span class="font-medium" x-text="item.name"></span><span class="text-stone-600" x-show="item.description" x-text="' — ' + item.description"></span></li>
                                    </template>
                                </ul>
                                <ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-stone-700" x-show="(content('services')?.prices ?? []).length > 0">
                                    <template x-for="price in (content('services')?.prices ?? [])"><li x-text="price"></li></template>
                                </ul>
                            </div>

                            <dl class="grid gap-x-4 gap-y-1 text-sm sm:grid-cols-[7rem_1fr]" x-show="key === 'contact' && content('contact')">
                                <template x-for="row in contactRows" :key="row.label">
                                    <div class="contents">
                                        <dt class="text-stone-500" x-text="row.label"></dt>
                                        <dd class="break-words" x-text="row.value"></dd>
                                    </div>
                                </template>
                            </dl>
                        </div>

                        {{-- Edit view --}}
                        <form class="mt-4 space-y-4" x-show="editing === key" @submit.prevent="saveSection">
                            <template x-if="key === 'hero'">
                                <div class="space-y-3">
                                    <div><label class="label" for="h-title">Business name</label><input id="h-title" class="field" x-model="draft.title" maxlength="150"></div>
                                    <div><label class="label" for="h-city">City</label><input id="h-city" class="field" x-model="draft.city" maxlength="100"></div>
                                    <div><label class="label" for="h-tag">Tagline</label><input id="h-tag" class="field" x-model="draft.tagline" maxlength="160" placeholder="One short line (optional)"></div>
                                </div>
                            </template>

                            <template x-if="key === 'about'">
                                <div class="space-y-3">
                                    <template x-for="(paragraph, index) in draft.paragraphs" :key="index">
                                        <div class="flex gap-2">
                                            <textarea class="field" rows="3" x-model="draft.paragraphs[index]" maxlength="1200" :aria-label="`Paragraph ${index + 1}`"></textarea>
                                            <button type="button" class="btn-danger shrink-0 self-start" @click="draft.paragraphs.splice(index, 1)">Remove</button>
                                        </div>
                                    </template>
                                    <button type="button" class="btn-link" x-show="draft.paragraphs.length < 6" @click="draft.paragraphs.push('')">+ Add a paragraph</button>
                                </div>
                            </template>

                            <template x-if="key === 'services'">
                                <div class="space-y-4">
                                    <div class="space-y-3">
                                        <template x-for="(item, index) in draft.items" :key="index">
                                            <div class="space-y-2 rounded-lg border border-stone-200 p-3">
                                                <div class="flex gap-2">
                                                    <input class="field" x-model="item.name" maxlength="100" placeholder="Service or product" :aria-label="`Service ${index + 1} name`">
                                                    <button type="button" class="btn-danger shrink-0" @click="draft.items.splice(index, 1)">Remove</button>
                                                </div>
                                                <input class="field" x-model="item.description" maxlength="300" placeholder="Short description (optional)" :aria-label="`Service ${index + 1} description`">
                                            </div>
                                        </template>
                                        <button type="button" class="btn-link" x-show="draft.items.length < 30" @click="draft.items.push({ name: '', description: '' })">+ Add a service</button>
                                    </div>
                                    <div>
                                        <label class="label" for="s-prices">Prices (one per line)</label>
                                        <textarea id="s-prices" class="field" rows="3" x-model="draft.prices" placeholder="Plum cake 250"></textarea>
                                    </div>
                                </div>
                            </template>

                            <template x-if="key === 'contact'">
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <div><label class="label" for="c-phone">Phone</label><input id="c-phone" class="field" x-model="draft.phone" maxlength="40"></div>
                                    <div><label class="label" for="c-email">Email</label><input id="c-email" class="field" type="email" x-model="draft.email" maxlength="254"></div>
                                    <div class="sm:col-span-2"><label class="label" for="c-address">Address</label><input id="c-address" class="field" x-model="draft.address" maxlength="300"></div>
                                    <div class="sm:col-span-2"><label class="label" for="c-hours">Opening hours</label><input id="c-hours" class="field" x-model="draft.hours" maxlength="200"></div>
                                    <div class="sm:col-span-2">
                                        <label class="label" for="c-social">Social links (one per line)</label>
                                        <textarea id="c-social" class="field" rows="2" x-model="draft.social" placeholder="https://instagram.com/yourshop"></textarea>
                                    </div>
                                </div>
                            </template>

                            <ul class="space-y-1" x-show="draftErrors.length > 0" role="alert">
                                <template x-for="message in draftErrors"><li class="error-text" x-text="message"></li></template>
                            </ul>

                            <div class="flex gap-2">
                                <button type="submit" class="btn btn-primary" :disabled="isBusy('save' + key)">Save</button>
                                <button type="button" class="btn btn-quiet" @click="cancelEditing">Cancel</button>
                            </div>
                        </form>
                    </section>
                </template>
            </div>
        </div>
    </div>
</div>
@endsection
