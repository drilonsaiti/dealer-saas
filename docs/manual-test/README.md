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

## 12. Contracts (Phase 2, step 1)

Needs Gotenberg (`docker compose up -d` starts it; `GOTENBERG_URL` points to it).

1. Settings → Company: upload a logo, set street and postcode. Settings → Bank accounts: one account.
2. Open a reserved or sold vehicle (e.g. the Skoda Octavia of the demo data) → **More → Kaufvertrag**.
3. Step "Vorbereiten": the warnings list what the contract misses (address, VIN, first registration).
   The language is proposed from the customer (Luca Rossi → Italiano). Add a remark.
4. Step "Prüfen": the preview shows the contract in that language, with the logo, the 9 clauses
   on page 2 and open promises (Zusagen) printed under remarks.
5. **Vertrag abschliessen**. Expected: notification "Vertrag KV-00001 abgeschlossen"; the
   document is in the vehicle file, folder 04 Verkauf, named `228461775_<date>_Contratto-di-vendita_IT.pdf`.
6. Change the remark and finalise again: same number KV-00001, version 2; version 1 stays.
   Finalise without changes: nothing new.
7. Change the customer's name: the finalised PDF stays as it was.
8. **More → Kaufvertrag Ankauf** on any vehicle with a purchase: purchase contract with seller and payoff.
9. Settings → Vorlagen: **Neue Version**, change a clause, save (draft v2). Delete all clauses of one
   language and activate: refused. Fill them in again, **Aktivieren**: v1 becomes "Abgelöst".
   A new contract uses v2; the old one still shows v1's clauses.

## 13. E-signature (Phase 2, step 2)

Emails land in Mailpit (http://localhost:8025). The Docker image has pyHanko, so signed PDFs are sealed.

**On the iPad / PC**
1. Vehicle file → Documents tab → finalised contract (status "Abgeschlossen") → **Unterschreiben**.
2. Choose "Hier auf diesem Gerät", check name and language → **Weiter**. The signing page opens.
3. Customer: enter ID type and number, tick "Ausweis geprüft", the customer ticks "gelesen und einverstanden",
   signs in the field → **Jetzt unterschreiben**. Expected: "Luca Rossi hat unterschrieben. Jetzt unterschreibt …".
4. Dealer: tick, sign → **Jetzt unterschreiben**. Expected: status "Unterschrieben", version 2
   `…_Unterschrieben.pdf` with both signatures and a third page "Nachweis der Unterschriften"
   (ID number shown as *****567). The customer gets the PDF by email.
5. Open the signed PDF in Adobe Reader: the signature panel shows the seal (self-signed certificate locally,
   so Reader says "validity unknown"; a CA certificate fixes that in production).

**By link**
1. Another finalised contract → **Unterschreiben** → "Per Link", the customer's email → **Weiter**.
   Status "Zur Unterschrift"; the email "… zum Unterschreiben" is in Mailpit.
2. Open the link in a private window (no login): document in the customer's language → **Code senden**
   → code from Mailpit → **Bestätigen** → tick, place, sign → **Jetzt unterschreiben**.
3. In the vehicle file: **Gegenzeichnen** → sign. Expected as above; the evidence page says
   "Link gesendet an … Einmalcode … bestätigt am …".

**Other cases**
- Wrong code five times: the code is blocked, ask for a new one.
- "Unterschrift zurückziehen" (with a reason): the link stops working, the contract is "Abgeschlossen" again.
- "Link erneut senden": a new link, the old one stops working.
- "Auf Papier unterschrieben": upload the scan; it becomes the signed version.
- A signed contract cannot be finalised again, replaced or deleted.
- Links expire after 14 days (`php artisan signatures:expire` runs hourly).

## 14. Invoices, QR bill, payments, bank import (Phase 2, step 3)

Settings → Company needs postcode and town, Settings → Bank accounts one account (with QR-IBAN the
reference is a 27-digit QR reference, without it an RF creditor reference).

1. Vehicle file with a sale → **More → Anzahlungsrechnung** (if the sale has a deposit) or **Schlussrechnung**.
   A draft opens: check the lines (vehicle with Stammnummer/VIN, items, discount, minus deposit invoices).
   **Entwurf bearbeiten** changes lines, VAT code, due date.
2. **Rechnung ausstellen**: the confirmation shows the number it will get (RE-00001…). Expected:
   number, status "Offen", PDF in the vehicle file (folder 04), last page with the Swiss QR bill.
   Scan the QR code with a banking app: account, amount, reference and "Fattura finale RE-…" appear.
3. Dealer with VAT number: "MWST 8.1 % auf CHF 27’700.00: CHF 2’075.58" (VAT of each rate on its total).
   Without VAT number: "Nicht MWST-pflichtig".
4. With a confirmed trade-in, the final invoice is partly paid by the trade-in (payment "Verrechnung Eintausch");
   the QR bill shows only the rest.
5. **Zahlung erfassen** (accounting): part payment → "Teilweise bezahlt", rest → "Bezahlt".
6. **Mehr → Gutschrift** (accounting): reason, empty amount = whole invoice → GS-00001, invoice "Durch Gutschrift
   storniert", the sale is back to "contracted" and can be cancelled. With an amount: partial credit.
   Cancelling a sale with an open invoice is refused until the credit note exists.
7. **Mehr → Per E-Mail senden**: PDF as attachment (Mailpit). Nothing is ever sent automatically.
8. Finance → Bankbuchungen → **Kontoauszug importieren**: a camt.054 / camt.053 file from e-banking.
   Payments with the invoice's QR/RF reference are booked at once (invoice "Bezahlt"); a credit with the exact
   open amount of one invoice is proposed ("Bitte bestätigen" → **Bestätigen**); others: **Rechnung zuordnen**
   or **Ignorieren**; **Zuordnung aufheben** undoes it. Importing the same file again: "… bereits importiert".
9. Finance → Rechnungen: tabs Offen / Überfällig / Entwürfe; the dashboard shows "Offene Rechnungen".
10. Finance → Zahlungen → **Zahlung erfassen** "Ausgang" for a purchase: the seller payment status updates
    (CHF 22’800 bank + CHF 5’000 cash for one purchase = two payments).

## 15. VAT / MWST (Phase 2, step 4)

The demo dealer Bern has VAT settings like Aziri: net tax rate method, 0.6 % "Autohandel", agreed
consideration, half-yearly (Settings → MWST). Read-only users can look; sales users do not see VAT;
closing, export, submission and payment need accounting or administrator (`vat.close`).

1. Settings → MWST → **Bearbeiten**: enter the five-digit ESTV activity code from the approval letter
   (needed for the XML). A change of method or rate is **Neue MWST-Einstellungen** with a later start date;
   settings used by a closed period cannot be edited.
2. Issue invoices (section 14). Finance → MWST: the half-year appears with the first issued invoice.
   **Aktualisieren** picks up invoices issued before the settings existed.
3. Open the period: "Vorschau, nicht vollständig" lists what is open. Fields 200 / 220 / 230 / 235 / 289 / 299,
   turnover and tax per net tax rate, amount payable (500). Example: CHF 500’000 taxable → CHF 3’000.
   Under **Buchungen** every entry shows the rule (invoice_line 2026.1) and why.
4. Invoice with code "Ohne MWST-Ausweis" or "Export": entry "Bitte bestätigen" → **Bestätigen** (export only
   with the proof in the file). An invoice issued with no VAT settings at all is "Blockiert".
5. After the end of the period, with no open checks: **Periode abschliessen**. Expected: status
   "Abgeschlossen", report PDF and CSV detail under **Dateien** (also Documents, folder "Firma / Steuern").
6. **ESTV-Export (XML)** downloads the eCH-0217 file (`MWST_…_eCH-0217.xml`). Without `resources/schemas/eCH-0217-2-0-0.xsd`
   the notification says it is not validated against the schema. Upload it in "MWST-Abrechnung pro".
7. **Als eingereicht markieren** (date, ESTV reference, the portal's confirmation PDF), then
   **Als bezahlt markieren**. Each is its own status.
8. A credit note after closing lands in the new period (field 235); the closed period never changes.
   With received consideration, a payment dated in a closed period creates a correction period
   ("Korrektur") whose XML replaces the original return.
9. Vehicle file → Margin: "Saldosteuer auf dem Verkauf" and "Marge nach MWST".

## 16. Leasing, buy-back, warranty, handover (Phase 2, step 5)

Demo data: leasing bank "Demo Leasing Bank AG", warranty products "Garantie Plus 12 Monate" (Demo Garantie AG)
and "Eigene Garantie 6 Monate". Settings → Garantieprodukte and Settings → Checklisten can be edited
(saving a checklist makes a new version).

**Leasing (acceptance test 7)**
1. Skoda Octavia (reserved for Luca Rossi) → **Mehr → Leasing / Kredit**: bank, first instalment collected
   CHF 4’500, term 48, residual CHF 11’000, "Rückkaufverpflichtung" on. Expected: "Erwartete Auszahlung CHF 18’400",
   payment type Leasing; the bank is now the invoice recipient.
2. **Kaufvertrag erfassen**. Finance → Leasing / Kredit → open it → **Bewilligt** → **Vertrag erhalten**
   (contract number). Expected: revocation until +14 days, "Unterlagen für die Bank" (8 items),
   Finance → Rückkaufverpflichtungen shows CHF 11’000 due at lease end.
3. **Unterlagen an die Bank gesendet** is refused until every required item is ticked. Items with a rule tick
   themselves (leasing contract / budget calculation uploaded to the vehicle file, sales contract signed, invoice issued).
4. Vehicle file → **Schlussrechnung** → issue: recipient is the bank, line "Erste Rate, einkassiert von Luca Rossi"
   −4’500 without VAT, VAT on the full price, open CHF 18’400.
5. **Übergeben**: refused ("Bezahlt bzw. Leasing ausbezahlt" missing) and warns about the revocation period.
6. Import the bank's payment (camt with the invoice reference) or **Zahlung erfassen** CHF 18’400: the financing
   is "Ausbezahlt", the dashboard's open payouts go down, the handover item ticks itself.
7. **Übergeben** again, tick "Schlüssel …": delivered. **Mehr → Code 178** → "Code 178 eingetragen".
8. Finance → Rückkaufverpflichtungen → **Zurückkaufen**: a new vehicle file on the same car (purchase type buy-back,
   seller = bank). Reserving it is refused while code 178 is entered; set "Code 178 gelöscht", then it works.

**Warranty (acceptance test 8)**
1. A reserved sale → **Mehr → Garantie hinzufügen** → product: price on the sale (contract, invoice), premium as
   cost (margin), tab "Garantien" shows a draft.
2. **Police registrieren**: policy number and certificate PDF; doing it again with another PDF adds version 2 of
   the same certificate. The handover item "Garantie registriert" ticks itself.
3. Hand over: the warranty becomes active from that date and km (Garantien → "Bis km").
4. Open the warranty → **Garantiefall melden** → **Entscheiden** (amount = deductible + provider + dealer) →
   **Abrechnen**: the dealer share is a confirmed cost on the original vehicle file, even if it is archived.
   A claim after the end date or above the km limit is flagged "Ausserhalb der Deckung".
5. Garantien → "Läuft innert 30 Tagen ab"; `php artisan warranties:expire` (daily) marks ended ones expired.

## 17. Preparation (concept 10.5)

1. A purchased or arrived vehicle file → tab **Zustand** → **Neuer Zustandsbericht**: rate each area, add damages
   (where, what, severity, photos). The photos land in the vehicle file's documents (folder 02).
2. Tab **Reparaturaufträge** → **Neuer Reparaturauftrag**: workshop, work, estimate, tick the damages it repairs.
   **Freigeben** with the approved amount: the margin's costs show "freigegebene Reparaturen" (provisional).
3. **Erledigt** with the workshop invoice amount: a confirmed cost of the file; the approved estimate disappears.
4. **Für Verkauf freigeben** (header): refused while an order that "blockiert die Freigabe" is open;
   otherwise the file is "Verkaufsbereit" with who and when. **Mehr → Termin Aufbereitung** sets the target date,
   shown red in the "Aufbereitung" section when it has passed.

## 18. Own listings, website, API and webhooks (Phase 3, step 1)

1. A vehicle file "Verkaufsbereit" with at least one photo (Documents tab, category Foto) → **Inserat veröffentlichen**:
   title per language (empty languages take German), description, highlights, price, photos and cover →
   **Veröffentlichen**. Expected: file "Inseriert", button "Inserat bearbeiten"; **Mehr → Inserat zurückziehen** undoes it.
2. Settings → API-Tokens → **Neues API-Token** (copy it from the notification; it is shown once).
3. `curl -H "Authorization: Bearer dsk_…" http://localhost:8000/api/v1/vehicles?lang=fr` lists the car;
   the photo URLs open in the browser without token. Reserve the car: `availability` becomes "reserved";
   after the sale "sold" for 7 days, then it disappears. A wrong token gives 401. Details: `docs/api.md`.
4. WordPress: copy `integrations/wordpress/dealer-saas-stock` to `wp-content/plugins`, activate, Settings → Dealer SaaS
   (API address `http://<host>/api/v1`, token, detail page), **Verbindung testen**. Pages with `[dealer_stock]` and
   `[dealer_vehicle]`. Send the enquiry form: Dealer SaaS → **Anfragen** shows it with the contact (found by e-mail
   or phone, otherwise created) and the dealer gets an e-mail (Mailpit).
5. Settings → Webhooks → address (e.g. a https://webhook.site URL), events. Publishing, reserving, selling,
   an enquiry or a paid invoice post a signed JSON; the delivery log is on the webhook's page (**Erneut senden**).

## 19. AutoScout24 (Phase 3, step 2)

The AutoScout24 CH DMS API is documented only for customers; until the real access data and
documentation are there, test against a stand-in (e.g. a https://webhook.site URL in
`AUTOSCOUT24_BASE_URL` / `AUTOSCOUT24_TOKEN_URL`) or only check the error handling.

1. Settings → Schnittstellen → **Portal verbinden**: AutoScout24, Client-ID, Client-Secret, Kundennummer, Aktiv.
   Expected: the secret is never shown again; editing with an empty secret keeps it.
2. **Verbindung testen**. With wrong data: red notification with the reason, status "Fehler", entry in the
   Abgleich-Protokoll. With working data: "Verbunden".
3. Publish a car (section 18). Expected: in the vehicle file, section "Inserat" → Kanäle "AutoScout24: Online"
   (or "Fehlgeschlagen – reason"). Change the price → one "Aktualisieren" entry in the log; saving again
   without changes adds nothing.
4. Reserve the car → stays online, marked reserved (or removed, if "Reservierte Fahrzeuge vom Portal nehmen" is on).
   Sell or withdraw it → "Entfernen" in the log, channel "Entfernt".
5. **Fahrzeuge vom Portal importieren** (queue worker must run). Expected: one file "In Prüfung" per portal car
   with list price, an advert draft with texts and photos; a car whose VIN is already known is linked;
   running it again skips everything. The summary is in the log.

## 20. Accounting export (Phase 3, step 3)

1. Log in as Accounting (or Administrator) → Finanzen → Buchhaltungsexport → **Konten**: the Swiss SME numbers are
   shown in grey; enter e.g. 3000 for "Fahrzeugverkauf" and an own number for a bank account → Speichern.
2. **Neuer Export**, "Bis und mit" = end of last month. The dialog shows how many invoices, payments, purchases and
   costs are waiting. Export → a line "2026-001" with the number of bookings; **Herunterladen (CSV)**.
3. Open the CSV in Excel: one booking per row (Datum, Beleg, Soll, Haben, Betrag, MWST-Satz, Text, Fahrzeugdossier …).
   The sum of all debits equals the sum of all credits; with the effective method VAT goes to 2200 / 1170, with the
   net tax rate method revenue is gross.
4. **Neuer Export** again for the same date → "nothing new to export". Enter an invoice dated last month → it comes
   with the next export.
5. **Export rückgängig machen** on the latest export → its records are exported again next time; older exports
   cannot be undone. Sales users do not see the menu.

## 21. E-mail inbox (Phase 3, step 4)

Use a test mailbox (e.g. a free account with IMAP, or a local Mailpit/Dovecot); run the scheduler or `php artisan mail:fetch`.

1. Settings → Postfächer → **Neues Postfach**: address, IMAP server/port/encryption/user/password, SMTP for replies.
   **Verbindung testen** → "Verbindung funktioniert"; a wrong password → red notification with the server's answer.
2. Send a mail to it from another account with a PDF attached and the Stammnummer (e.g. 123.456.789) or VIN of a car
   in the text. **Jetzt abholen** → Posteingang shows it (bold until opened), assigned to the car and, if the sender's
   e-mail is a known contact, to that contact. The PDF is in the vehicle file's Documents (Korrespondenz) and in the
   E-Mails tab of the file. The mail stays unread on the server.
3. Send a mail with an attachment named `test.pdf.exe` or a `.docm`: it appears under "Nicht übernommen (Quarantäne)".
4. **Antworten**: text, optionally documents of the file → **Entwurf speichern**. Nothing is sent (Filter "Entwürfe").
   **Senden** → arrives in the other account as reply in the same conversation; the original is "Erledigt".
5. A mail without any number: **Zuordnen** to a vehicle file → its attachments move to that file.
6. Read-only users do not see the inbox; only administrators see the mailbox settings.

## 22. Calendar subscription (Phase 3, step 5)

1. Einstellungen → Mein Kalender → **Kalender-Link erstellen**; copy the link (shown once).
2. Outlook: Kalender hinzufügen → Aus dem Internet; Google Calendar: Weitere Kalender → Per URL; Apple: Ablage → Neues
   Kalenderabonnement. Expected: all-day entries for planned handovers, reservations ending, MFK due, "verkaufsbereit
   bis", repair targets, warranties ending, buy-back reminders and payouts due; each opens the record in Dealer SaaS.
3. **Neuen Link erstellen** → the old link gives 404, the new one works. **Ausschalten** → 404. A user removed from the
   dealer: the link stops working.

## 23. Vehicle data from Auto-i-DAT (Phase 3, step 6)

Needs Auto-i-DAT access (or a stand-in in `AUTOIDAT_BASE_URL`).

1. Settings → Schnittstellen → **Dienst verbinden** → Auto-i-DAT: Kundennummer, API-Schlüssel, Aktiv.
   **Verbindung testen**: wrong key → red notification "Zugriff verweigert".
2. A vehicle file with Typengenehmigung (or FIN) → **Mehr → Fahrzeugdaten abrufen**: choose the variant, tick the options
   the car really has, leave "überschreiben" off → **Übernehmen**. Expected: empty fields (Variante, Leistung, Gewichte …)
   filled, filled fields unchanged, options added to the equipment.
3. With "Bewertung abrufen": notification with sale and trade-in value; the Fahrzeug section shows "Marktwert" with date
   and mileage. Each call is in the Abgleich-Protokoll of the Auto-i-DAT integration.
4. AutoScout24 and Auto-i-DAT can both be connected; publishing still goes only to AutoScout24.

## 24. Warranty providers by e-mail (Phase 3, step 7)

Needs a mailbox with SMTP (section 21) and Gotenberg.

1. Settings → Garantieprodukte → product with Anbieter (e.g. NSA) → "Anmeldungen und Schäden" = "Per E-Mail an den
   Anbieter senden", optionally an own e-mail address (empty = e-mail of the provider contact).
2. Sell a car with this warranty → Garantien → the warranty → **An Anbieter senden**. Expected: PDF "Garantie-Anmeldung"
   in the vehicle file (Garantie), a draft in the Posteingang (filter Entwürfe) to the provider with the PDF; on the
   warranty "Entwurf vorbereitet … – im Posteingang senden". Nothing is sent yet.
3. Send the draft in the inbox → the warranty shows "gesendet …".
4. Report a claim → **Dokumente** (upload photo and Kostenvoranschlag) → **An Anbieter senden**: draft with
   "garantiefall.pdf" and both documents attached.
5. A product set to "Eigene Garantie / von Hand" has no "An Anbieter senden"; without SMTP mailbox or provider e-mail a
   red notification says what is missing.

## 25. AI texts and reply drafts (Phase 4, step 1)

Needs `DEALER_AI_API_KEY` (Anthropic) in `.env`.

1. Einstellungen → Firmenprofil → KI-Unterstützung: "KI-Unterstützung verwenden" on → Speichern. The helper text shows
   the requests used this month. Without the key the switch is greyed out.
2. Vehicle file → **Inserat veröffentlichen/bearbeiten** → **Texte mit KI schreiben** (optional notes) → **Schreiben**.
   Expected: title, description per language and highlights filled; German without "ß"; no price, no invented equipment.
   Edit and save as usual.
3. Posteingang → e-mail (e.g. in French) → **Antworten** → **Mit KI entwerfen** (optional "Probefahrt anbieten").
   Expected: reply in French above the quoted e-mail; unknown points as [placeholders]. Nothing is sent until **Senden**.
4. Switch AI off → both buttons disappear. With a wrong key: red notification with the reason; nothing changes in the form.
