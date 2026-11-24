import { api, ApiError } from './api';
import { sites } from './store';

// The start page: collect what the owner has, create the site, then send them to their workspace.
export default () => ({
    name: '',
    city: '',
    links: [''],
    note: '',
    files: [],
    errors: {},
    message: '',
    busy: false,
    recent: sites.list(),

    addLink() {
        if (this.links.length < 10) this.links.push('');
    },

    removeLink(index) {
        this.links.splice(index, 1);
        if (this.links.length === 0) this.links = [''];
    },

    pickFiles(event) {
        this.files = Array.from(event.target.files);
    },

    firstError(field) {
        return this.errors[field]?.[0] ?? '';
    },

    async submit() {
        if (this.busy) return;
        this.busy = true;
        this.errors = {};
        this.message = '';

        try {
            const links = this.links.map((link) => link.trim()).filter(Boolean);
            const created = await api('/sites', {
                method: 'POST',
                json: { business_name: this.name, city: this.city || null, links, note: this.note || null },
            });

            const id = created.site.id;
            sites.save(id, created.edit_token, created.site.business_name, created.site.city);

            // The site exists now. If a file fails, the owner can add it again in their workspace.
            if (this.files.length > 0) {
                const form = new FormData();
                this.files.forEach((file) => form.append('files[]', file));
                try {
                    await api(`/sites/${id}/files`, { method: 'POST', token: created.edit_token, form });
                } catch (error) {
                    sessionStorage.setItem('shopfront.flash', `Your site was created, but the files were not added: ${error.messages[0]}`);
                }
            }

            window.location.href = `/sites/${id}`;
        } catch (error) {
            if (!(error instanceof ApiError)) throw error;
            this.errors = error.errors;
            this.message = Object.keys(error.errors).length ? 'Please fix the highlighted fields.' : error.message;
            this.busy = false;
        }
    },
});
