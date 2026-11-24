# Demo video script (about 5 minutes)

Record the screen with your voice. Use two real businesses, ideally one that shows the "which business?" question and one that shows a conflict or a file.

Before recording: `composer dev` is running, the database is migrated, `GEMINI_API_KEY` is set, and the browser is on http://localhost:8000.

## 0:00 Intro (20 sec)
"This is Shopfront. A business owner gives a name and city, and gets an editable website. The rule I built it around: it never invents facts. If it is not sure, it asks."

## 0:20 Business 1: start and sources (60 sec)
- Enter the first real business name and city.
- Add its website or Instagram link, and a short typed note.
- Upload a PDF or a price list photo if you have one.
- Point out the "Web search" source that was added by itself.
- Click **Build**.

## 1:20 It asks instead of guessing (60 sec)
- Show the question card (two businesses with the same name, or two phone numbers).
- Say: "Both phone numbers are shown with where each came from. A wrong number on a shop's page is worse than a slow page."
- Answer it. Show the page building.

## 2:20 Edit, regenerate, publish (60 sec)
- Open **What we know**: each fact is tied to a source.
- Edit the About text by hand.
- Click **Regenerate** on Services. Say: "It runs in the background, so a slow AI cannot freeze the page."
- Click **Publish**, open the public link, show the page.

## 3:20 Business 2 (50 sec)
- Do the second business quickly, using a different kind of source (for example a social link that blocks reading).
- Show the notice: "Instagram blocked us, paste the text instead." Paste text and rebuild.

## 4:10 Safety and failure (40 sec)
- Mention (no need to demo all): hidden instructions inside a PDF are stripped and the owner is warned; links to internal addresses are refused; if Gemini is down, sources are kept and there is a "Try again" button.
- Optional: show a PDF containing "ignore previous instructions" and the warning it produces.

## 4:50 Close (10 sec)
"Code, README and DECISIONS are on GitHub. Thanks for watching."

## Tips
- Keep the Gemini wait short on camera: cut the waiting out when you edit.
- Do not show `.env` or your API key on screen.
- Say what you would do next: sign-in by email and OCR for scanned menus.
