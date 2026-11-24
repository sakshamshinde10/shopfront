import { api, ApiError } from './api';
import { sites } from './store';

const KEYS = ['hero', 'about', 'services', 'contact'];

const TITLES = {
    hero: 'Top of the page',
    about: 'About',
    services: 'Services and prices',
    contact: 'Contact',
};

const STATUS = {
    draft: ['Not built yet', 'bg-stone-100 text-stone-700'],
    processing: ['Building…', 'bg-sky-100 text-sky-800'],
    needs_input: ['Needs your answers', 'bg-amber-100 text-amber-800'],
    ready: ['Ready to publish', 'bg-teal-100 text-teal-800'],
    published: ['Published', 'bg-emerald-100 text-emerald-800'],
    failed: ['Something went wrong', 'bg-red-100 text-red-800'],
};

const lines = (text) =>
    String(text ?? '')
        .split('\n')
        .map((line) => line.trim())
        .filter(Boolean);

// The owner's workspace for one site: what we know, what we need to ask, the page itself.
export default (id) => ({
    id,
    token: null,
    site: null,
    loading: true,
    error: '',
    flash: '',
    busy: {},
    timer: null,
    copied: '',

    link: '',
    note: '',
    addErrors: [],

    editing: null,
    draft: {},
    draftErrors: [],
    sectionNotes: {},
    answers: {},
    answerErrors: {},

    keys: KEYS,
    titles: TITLES,

    // ---- start-up ------------------------------------------------------------------

    async init() {
        // A private edit link carries the key after the "#", which the server never sees.
        const match = window.location.hash.match(/key=([A-Za-z0-9]+)/);
        if (match) {
            sites.save(this.id, match[1], '', '');
            history.replaceState(null, '', window.location.pathname);
        }

        this.flash = sessionStorage.getItem('shopfront.flash') ?? '';
        sessionStorage.removeItem('shopfront.flash');

        this.token = sites.token(this.id);
        if (!this.token) {
            this.loading = false;

            return;
        }

        await this.refresh();
        this.loading = false;
        this.schedule();
    },

    destroy() {
        clearTimeout(this.timer);
    },

    /** While the server is working, ask again every few seconds. Otherwise stay quiet. */
    schedule() {
        clearTimeout(this.timer);
        this.timer = setTimeout(async () => {
            if (this.working) await this.refresh();
            this.schedule();
        }, 3000);
    },

    async refresh() {
        try {
            const response = await api(`/sites/${this.id}`, { token: this.token });
            this.site = response.data;
            sites.save(this.id, this.token, this.site.business_name, this.site.city);
        } catch (error) {
            this.handle(error);
            if (error.status === 404) {
                this.error = 'This key does not open this site. Use the private edit link you saved when you created it.';
                sites.forget(this.id);
                this.token = null;
            }
        }
    },

    /** Always true or false, so it is safe to use in a :disabled binding. */
    isBusy(name) {
        return Boolean(this.busy[name]);
    },

    /** Run an action with a busy flag and one place for errors. */
    async run(name, action) {
        this.busy[name] = true;
        this.error = '';
        try {
            await action();
        } catch (error) {
            this.handle(error);
        } finally {
            this.busy[name] = false;
        }
    },

    handle(error) {
        if (!(error instanceof ApiError)) throw error;
        this.error = error.message;
    },

    // ---- what the page needs to know ------------------------------------------------

    get working() {
        return Boolean(this.site && (this.site.status === 'processing' || this.site.pending_sources > 0 || this.site.regenerating));
    },

    get statusLabel() {
        return STATUS[this.site?.status]?.[0] ?? '';
    },

    get statusClass() {
        return STATUS[this.site?.status]?.[1] ?? '';
    },

    get blocking() {
        return (this.site?.questions ?? []).filter((question) => question.blocking);
    },

    get optional() {
        return (this.site?.questions ?? []).filter((question) => !question.blocking);
    },

    get canBuild() {
        return this.site && !this.working && this.site.status !== 'published';
    },

    get buildLabel() {
        return ['draft', 'failed'].includes(this.site?.status) ? 'Build my website' : 'Build again';
    },

    get hasSections() {
        return (this.site?.sections ?? []).length > 0;
    },

    get editLink() {
        return `${window.location.origin}/sites/${this.id}#key=${this.token}`;
    },

    /** The contact details as simple label/value rows, skipping anything empty. */
    get contactRows() {
        const c = this.content('contact') ?? {};
        const rows = [
            ['Phone', c.phone],
            ['Email', c.email],
            ['Address', c.address],
            ['Hours', c.hours],
            ['Links', (c.social ?? []).join(', ')],
        ];

        return rows.filter(([, value]) => value).map(([label, value]) => ({ label, value }));
    },

    content(key) {
        return this.site?.sections?.find((section) => section.key === key)?.content ?? null;
    },

    edited(key) {
        return this.site?.sections?.find((section) => section.key === key)?.edited_by_owner ?? false;
    },

    sourceState(source) {
        return {
            pending: ['Reading…', 'bg-sky-100 text-sky-800'],
            done: ['Read', 'bg-teal-100 text-teal-800'],
            blocked: ['Needs your help', 'bg-amber-100 text-amber-800'],
            failed: ['Could not read', 'bg-red-100 text-red-800'],
        }[source.status];
    },

    kindLabel(source) {
        return { link: 'Link', file: 'File', note: 'Note', search: 'Web search' }[source.kind] ?? source.kind;
    },

    // ---- sources -----------------------------------------------------------------------

    async addLink() {
        if (!this.link.trim()) return;
        await this.run('addLink', async () => {
            this.addErrors = [];
            try {
                await api(`/sites/${this.id}/sources`, { method: 'POST', token: this.token, json: { kind: 'link', url: this.link.trim() } });
                this.link = '';
                await this.refresh();
            } catch (error) {
                if (error instanceof ApiError && error.status === 422) this.addErrors = error.messages;
                else throw error;
            }
        });
    },

    async addNote() {
        if (!this.note.trim()) return;
        await this.run('addNote', async () => {
            this.addErrors = [];
            try {
                await api(`/sites/${this.id}/sources`, { method: 'POST', token: this.token, json: { kind: 'note', text: this.note } });
                this.note = '';
                await this.refresh();
            } catch (error) {
                if (error instanceof ApiError && error.status === 422) this.addErrors = error.messages;
                else throw error;
            }
        });
    },

    async addFiles(event) {
        const files = Array.from(event.target.files);
        if (files.length === 0) return;
        await this.run('addFiles', async () => {
            this.addErrors = [];
            const form = new FormData();
            files.forEach((file) => form.append('files[]', file));
            try {
                await api(`/sites/${this.id}/files`, { method: 'POST', token: this.token, form });
                await this.refresh();
            } catch (error) {
                if (error instanceof ApiError && error.status === 422) this.addErrors = error.messages;
                else throw error;
            } finally {
                event.target.value = '';
            }
        });
    },

    async removeSource(source) {
        if (!confirm(`Remove "${source.label ?? source.url}"? Facts taken from it will be removed too.`)) return;
        await this.run(`remove${source.id}`, async () => {
            await api(`/sites/${this.id}/sources/${source.id}`, { method: 'DELETE', token: this.token });
            await this.refresh();
        });
    },

    // ---- building and asking -------------------------------------------------------------

    async build() {
        await this.run('build', async () => {
            const response = await api(`/sites/${this.id}/generate`, { method: 'POST', token: this.token });
            this.site = response.data;
        });
    },

    async answer(question, value) {
        const answer = String(value ?? '').trim();
        if (!answer) return;
        await this.run(`answer${question.id}`, async () => {
            this.answerErrors[question.id] = '';
            try {
                const response = await api(`/sites/${this.id}/questions/${question.id}/answer`, {
                    method: 'POST',
                    token: this.token,
                    json: { answer },
                });
                this.site = response.data;
                delete this.answers[question.id];
            } catch (error) {
                if (error instanceof ApiError && error.status === 422) this.answerErrors[question.id] = error.messages[0];
                else throw error;
            }
        });
    },

    // ---- editing a section ------------------------------------------------------------------

    startEditing(key) {
        const c = this.content(key) ?? {};
        this.draftErrors = [];
        this.editing = key;
        this.draft = {
            hero: { title: c.title ?? this.site.business_name, city: c.city ?? '', tagline: c.tagline ?? '' },
            about: { paragraphs: c.paragraphs?.length ? [...c.paragraphs] : [''] },
            services: {
                items: c.items?.length ? c.items.map((item) => ({ name: item.name, description: item.description ?? '' })) : [{ name: '', description: '' }],
                prices: (c.prices ?? []).join('\n'),
            },
            contact: {
                phone: c.phone ?? '',
                email: c.email ?? '',
                address: c.address ?? '',
                hours: c.hours ?? '',
                social: (c.social ?? []).join('\n'),
            },
        }[key];
    },

    cancelEditing() {
        this.editing = null;
        this.draftErrors = [];
    },

    /** The draft as the API expects it: blank lines dropped, cleared contact fields sent as null. */
    payload(key) {
        const d = this.draft;

        return {
            hero: { title: d.title, city: d.city || null, tagline: d.tagline || null },
            about: { paragraphs: d.paragraphs?.map((p) => p.trim()).filter(Boolean) },
            services: {
                items: d.items?.filter((item) => item.name.trim()).map((item) => ({ name: item.name.trim(), description: item.description.trim() || null })),
                prices: lines(d.prices),
            },
            contact: {
                phone: d.phone || null,
                email: d.email || null,
                address: d.address || null,
                hours: d.hours || null,
                social: lines(d.social),
            },
        }[key];
    },

    async saveSection() {
        const key = this.editing;
        await this.run(`save${key}`, async () => {
            this.draftErrors = [];
            try {
                await api(`/sites/${this.id}/sections/${key}`, { method: 'PATCH', token: this.token, json: this.payload(key) });
                this.editing = null;
                await this.refresh();
            } catch (error) {
                if (error instanceof ApiError && error.status === 422) this.draftErrors = error.messages;
                else throw error;
            }
        });
    },

    async regenerate(key) {
        if (this.edited(key) && !confirm('This replaces the changes you made to this section. Continue?')) return;
        await this.run(`regen${key}`, async () => {
            this.sectionNotes[key] = '';
            try {
                // The server accepts the request and rewrites in the background; polling shows "Writing…" until it is done.
                const response = await api(`/sites/${this.id}/sections/${key}/regenerate`, { method: 'POST', token: this.token });
                this.site = response.data;
                this.schedule();
            } catch (error) {
                if (error instanceof ApiError && [409, 422].includes(error.status)) this.sectionNotes[key] = error.message;
                else throw error;
            }
        });
    },

    // ---- publishing ----------------------------------------------------------------------------

    async publish() {
        await this.run('publish', async () => {
            await api(`/sites/${this.id}/publish`, { method: 'POST', token: this.token });
            await this.refresh();
        });
    },

    async unpublish() {
        await this.run('publish', async () => {
            await api(`/sites/${this.id}/publish`, { method: 'DELETE', token: this.token });
            await this.refresh();
        });
    },

    async copy(what, text) {
        try {
            await navigator.clipboard.writeText(text);
            this.copied = what;
            setTimeout(() => (this.copied = ''), 2000);
        } catch {
            this.error = 'Could not copy. Select the text and copy it yourself.';
        }
    },
});
