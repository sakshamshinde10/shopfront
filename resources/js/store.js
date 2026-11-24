// The owner's keys live in this browser only. There are no accounts: whoever has a site's key can
// edit it, so the key is kept here and never sent anywhere except to our own API.

const KEY = 'shopfront.sites';

// localStorage can be blocked (private mode, strict settings), so every access is guarded.
function read() {
    try {
        return JSON.parse(localStorage.getItem(KEY)) ?? {};
    } catch {
        return {};
    }
}

function write(value) {
    try {
        localStorage.setItem(KEY, JSON.stringify(value));
    } catch {
        /* the owner can still use the private edit link */
    }
}

export const sites = {
    list() {
        return Object.entries(read())
            .map(([id, site]) => ({ id: Number(id), ...site }))
            .sort((a, b) => b.savedAt - a.savedAt);
    },

    save(id, token, name, city) {
        write({ ...read(), [id]: { token, name, city, savedAt: Date.now() } });
    },

    token(id) {
        return read()[id]?.token ?? null;
    },

    forget(id) {
        const all = read();
        delete all[id];
        write(all);
    },
};
