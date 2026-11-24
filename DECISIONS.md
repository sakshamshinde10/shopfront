# Decisions

## Key choices

**Facts first, prose second.** The AI never writes straight into the page. It first extracts facts, each with an exact quote from the source. Code checks the quote. The page is then written from confirmed facts only. This is the main defence against invented details, and it is testable without calling an AI.

**The AI is kept away from what it cannot be trusted with.** Contact details, prices and the services list are copied from facts. The writer is not even shown phone numbers, hours or prices. During testing it repeated a price inside a service description, so removing the data was more reliable than asking it not to.

**Ask, don't guess.** Conflicts and same-name businesses become questions. Only the ones that would put wrong information on the page block the build. Missing basics (no address) are optional questions.

**No accounts.** Creating a site returns a long random key that works as the owner's password for that site. It is fast to start and there is no password to leak. The cost: lose the link and the browser copy, lose the site. A real product would add email sign-in.

**OpenStreetMap instead of Gemini web grounding.** Gemini's search grounding is not on the free quota. OpenStreetMap (Nominatim) gives real business listings with addresses and phone numbers for free and is cached for 24 hours. Websites found this way are read through the same safe fetcher and the same quote checks as anything else.

**A list of Gemini models.** Free-tier Gemini models are often overloaded. The client tries the models in `GEMINI_MODELS` in order, retries once on busy answers, moves on at once after a timeout, and retries without the "thinking" setting for models that reject it.

**Background jobs.** Building and regenerating run in a queue. A synchronous regenerate hit PHP's 30 second limit when Gemini was slow. The page polls every 3 seconds and shows "Writing…".

**A public page without JavaScript.** The published site is plain HTML with a strict Content-Security-Policy. Nothing the owner or a source wrote can run as a script.

**Fetching is treated as dangerous.** Owner-supplied links are a classic server-side request forgery risk, so the fetcher only allows public IPv4 addresses, pins the resolved address, re-checks every redirect and caps the size.

## What I cut

- Reading Instagram or Facebook directly. They block it. The app says so and accepts pasted text instead.
- OCR for scanned PDFs and photos of menus. Only text PDFs are read.
- Custom domains. A published site lives at `/s/{slug}`.
- Themes and layouts. There is one clean template.
- Accounts, password reset, and managing several sites per person.
- Image editing and choosing which photo is the hero.

## What I would do next

1. Email sign-in so a site can be recovered, with the edit key as a fallback.
2. OCR (or Gemini's vision input) for scanned price lists and menu photos.
3. Google Places as an optional listing source, for owners who supply a key.
4. Several pages per site, and a few templates.
5. Rate limits per site on AI calls, and a daily AI budget.
6. A proper deploy: queue supervisor, HTTPS, and object storage for uploads.

## Known limits

- Builds can take from a few seconds to a couple of minutes when Gemini is busy.
- OpenStreetMap does not list every small shop, so some businesses will need a note or a link from the owner.
- The "claim word" check is a short list, so a subtle unsupported claim in an uncommon wording could pass.
