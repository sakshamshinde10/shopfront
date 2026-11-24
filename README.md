# Shopfront

Turns what a business owner already has (name, city, social links, a Google listing, PDFs, photos) into a website they can edit and publish, without inventing facts.

The owner gives a business name and city, plus anything else they have. Shopfront reads it, searches the web, finds the facts, asks when it is not sure, writes the page, and publishes it at a public address. Everything is saved in MySQL.

Built with Laravel 13, MySQL and Gemini. The pages use Vite, Tailwind and Alpine.

## What it does

1. **Start** with a business name and city. No account is needed.
2. **Add sources**: links (website, Instagram, Facebook), a typed note, or files (PDF, price list, photos). The app also searches the web for the business.
3. **Build**. The AI extracts facts from every source. Each fact must come with a quote that really appears in the source.
4. **Answer questions** when the sources disagree (two phone numbers), when two businesses share a name, or when something basic is missing.
5. **Edit** any section by hand, or **regenerate** one section.
6. **Publish** to `/s/{slug}`, a script-free public page. Take it offline at any time.

## Run it locally

You need PHP 8.3+, Composer, Node 20+ and MySQL 8.

First create an empty database:

```sql
CREATE DATABASE shopfront CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Then install and set up:

```powershell
composer setup
```

This installs everything, creates `.env`, generates the app key, runs the migrations and builds the assets. (If it stops at the migrations, set the database login in `.env` as below and run `php artisan migrate`.)

In `.env`, set:

- `DB_USERNAME` and `DB_PASSWORD` for your MySQL.
- `GEMINI_API_KEY` with your own key from Google AI Studio. The key is only ever read from `.env`. It is never committed.

Start the web server, the queue worker and Vite together:

```powershell
composer dev
```

Or, without Vite (the assets are already built), in two terminals:

```powershell
php artisan serve
php artisan queue:work
```

The queue worker is required. Building a site and regenerating a section run in the background, because the AI can be slow.

Open http://localhost:8000.

### Tests

```powershell
php artisan test
```

The tests use a MySQL database named `shopfront_test` (create it once). They never call Gemini or the internet: the AI and the DNS lookups are faked.

## How "never invent facts" is enforced

This is done in code, not just in the prompt.

- **Quote proof.** Every extracted fact carries an exact quote. If the quote is not in the source text, or the value is not in the quote, the fact is dropped. Phone numbers are compared by their digits.
- **The AI only phrases.** Contact details, the services list, prices and the fallback "about" text are copied from confirmed facts. The AI writes only the tagline, the about text and short service descriptions. It is not even shown phone numbers, hours or prices.
- **Checked prose.** Every sentence the AI writes is checked against the facts. Sentences with numbers, emails, links or claim words (best, award-winning, since 1990) that the facts do not support are removed.
- **Questions instead of guesses.** Conflicting facts and same-name businesses become questions. Questions that change the page (which business, which phone) block building until answered.
- **Hidden instructions.** Sentences in uploaded files or pages that talk to an AI ("ignore previous instructions") are removed before the AI sees them, and the owner is warned.
- **Unsure means empty.** No tagline is shown when there is nothing to base one on.

## Edge cases

| Situation | What happens |
|---|---|
| Two businesses share a name | The app lists the candidates (from OpenStreetMap) and asks which one |
| Two phone numbers | Both are shown with their sources; the owner picks one |
| Instagram or Facebook blocks reading | The source is marked as blocked, the owner is told, and can paste the text as a note |
| Instructions hidden in a PDF | Stripped, and the owner sees a warning |
| Gemini is down or slow | Tries the next model in `GEMINI_MODELS`. If all fail, the sources are kept and the page offers "Try again". A plain version (facts only) is shown when only the writing fails |
| A link points at an internal address | Refused (see Security) |

## Security

- **No API keys in the repo.** `.env` is ignored; `.env.example` has an empty key.
- **Private edit link.** Creating a site returns a long random edit key. It is compared in constant time, and a wrong key gets the same "Not found" as a site that does not exist. The key is kept in the browser and in the link's `#` part, which is never sent to the server.
- **Safe fetching.** Links are fetched only on ports 80/443, only to public IPv4 addresses (the address is resolved once and pinned, redirects are re-checked, responses are capped at 1 MB).
- **Uploads.** Files are checked by content, not by name. Photos stay private until the site is published, and only jpeg, png and webp are ever served.
- **Public page.** No JavaScript, a strict Content-Security-Policy, escaped output, and only `tel:`, `mailto:` and http(s) links.

## Database design

Five tables, all tied to `sites`.

| Table | Holds |
|---|---|
| `sites` | Business name, city, slug, edit key, status, notice, the section being rewritten, publish time |
| `sources` | Each link, file, note or search result; its extracted text and any warning |
| `facts` | One row per found fact: field, value, the quote, which source, status |
| `questions` | What the app needs the owner to decide, its options and the answer |
| `sections` | The written page: hero, about, services, contact, and whether the owner edited it |

## API

All routes are under `/api`. Owner routes need `Authorization: Bearer <edit key>`.

| Method | Path | Purpose |
|---|---|---|
| POST | `/sites` | Create a site (returns the edit key once) |
| GET | `/sites/{id}` | The whole workspace: sources, facts, questions, sections |
| POST | `/sites/{id}/sources` | Add a link or note |
| POST | `/sites/{id}/files` | Upload a PDF or photo |
| DELETE | `/sites/{id}/sources/{source}` | Remove a source |
| POST | `/sites/{id}/generate` | Build (or rebuild) the site |
| POST | `/sites/{id}/questions/{question}/answer` | Answer or skip a question |
| PATCH | `/sites/{id}/sections/{key}` | Edit a section by hand |
| POST | `/sites/{id}/sections/{key}/regenerate` | Rewrite one section (runs in the background) |
| POST / DELETE | `/sites/{id}/publish` | Publish or take offline |
| GET | `/public/{slug}` | The published site as JSON |

See [DECISIONS.md](DECISIONS.md) for the key choices, what was left out, and what comes next.
