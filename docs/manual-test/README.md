# Manual test workflow

Sample files are in `docs/manual-test/files/` (regenerate with `php artisan dealer:manual-test-files`).
Menu names below are the German ones; switch the language in the profile if you want another.

## 0. Setup

```bash
cp .env.example .env                                   # QUEUE_CONNECTION=sync: text reading runs immediately
docker compose up -d                                   # app on http://localhost:8000
docker compose exec app php artisan migrate:fresh --seed
```

Without Docker for the app: `docker compose up -d postgres redis mailpit gotenberg`, then
`php artisan migrate:fresh --seed` and `php artisan serve`.

Logins (password `password` for all):

| Who | E-mail | URL |
|---|---|---|
| Dealer Bern (de) | `admin@demo-bern.example.ch` | `/app` |
| Dealer Vevey (fr) | `admin@demo-vevey.example.ch` | `/app` |
| Platform | `platform@example.ch` | `/platform` |

OCR needs Tesseract on your machine (the Docker image has it). Without it the "Read text now" action reports an error; everything else works.

## 1. Language

1. Platform → your profile: language is "Automatic (browser)". Pick Englisch, save: the whole UI switches.
2. Platform → Dealers → Demo Garage Bern → edit → save. Expected: saved, **no 403**.
3. Change the dealer's default language to Französisch. Log in as `admin@demo-bern.example.ch`: UI is French (the user has no own language).
4. Dealer profile (`/app/profile`): choose Italienisch → UI is Italian. Choose "Automatic" again → back to the dealer language.
5. Company profile (`/app/{dealer}/profile`): change the dealer language, save → the page reloads in that language.

## 2. Import vehicles (as Bern admin)

Import → Vehicles → upload `1-Fahrzeuge.xlsx`, sheet **Fahrzeuge** (the sheet "Info" is only an explanation). Accept the suggested column mapping, run the check (dry run), then import.

Expected: 7 rows created, 6 vehicles (201 and 205 are the same BMW: sold, then bought back).

| Nr. | Expected |
|---|---|
| 201 BMW X3 (512.664.318) | number `2025-0201`, status Delivered |
| 202 VW Golf | trade-in |
| 203 Audi A4 | in stock |
| 204 Toyota Yaris | Sold |
| 205 BMW | second cycle on the same BMW, in stock |
| 206 Lada Niva | row flagged: invalid Stammnummer / no price (check the notes) |
| 207 Skoda Octavia | Cancelled |

Run the same file again: nothing new is created.

## 3. Import costs

Import → Costs → `2-Kosten.xlsx`. Expected: 5 created, 2 errors (K0106 unknown vehicle 999, K0107 no date), K0105 has the note "unclear". Open the BMW: costs and margin update.

## 4. Import documents

Import → Documents → `3-Dokumente.zip`. Expected: 7 created, 1 skipped (`Kaufvertrag-Kopie.pdf` is an exact duplicate), 2 unassigned (unknown Stammnummer 999.999.999 and `Unsortiert/Notiz.pdf`, they land in the inbox). Junk (`__MACOSX`, `.DS_Store`) is ignored. The BMW now has 4 documents.

## 5. Rollback

Open the import run → Roll back. Expected: its records disappear (a run whose records already have sales/costs on top is refused, with a reason). Import again.

## 6. Photos and listing

BMW 205 → Photos → upload `foto-vorne.png`, `foto-hinten.png`, set the first as cover. Then publish/list the vehicle; the listing shows the cover.

## 7. Scan and OCR

Any vehicle → Documents → upload `scan-kaufvertrag.png`, category purchase contract. Status shows "pending". Use the row action **Read text now** (or `php artisan documents:ocr`). Expected: status "done", and searching for a word from the scan finds the document.

Search works in the documents list and in the global search at the top (Ctrl+K). Every word must
match the title, file name, recognised text or the vehicle / contact the document belongs to; word
beginnings are enough. With the demo data, "Kaufvertrag Toyota Corolla", "Kaufv Coro" and
"683.737.537" all find the Corolla purchase contract; the global search result opens the vehicle
file's documents tab. Without `sync` and without `php artisan queue:work`, OCR stays pending; that is the reason it did before.

## 8. Download names

Download any document: the file is named `<folder no>_<folder>_<category>_<car>_<date>_<seq>`, e.g. `01_Ankauf_Kaufvertrag_BMW-X3_2025-11-20_01.pdf`. Export the vehicle file as ZIP: every entry has the same naming, grouped in the six folders, numbered per category.

## 9. Sale workflow

Take the Audi A4 (203): Reserve → sale with customer, price, a trade-in, payment → handover. Expected: status Reserved → Sold → Delivered, invoice number from the number series, margin and dashboard figures updated.

## 10. Export and re-import

Vehicles list → Export. Re-import that file with the vehicles importer: nothing is duplicated.

## 11. Platform checks

- Platform → Restore drills: record a drill; after 35 days without one a warning shows.
- Isolation: log in as Vevey; none of Bern's vehicles, parties, documents or search results appear. The automated version is `tests/Feature/Tenancy/IsolationSuiteTest.php`.
