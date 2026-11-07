# Changelog

## 0.1.1 (2026-11-07)

- Fix: `<barcode type="QR">` failed with "Mpdf\QrCode package was not found". The package `mpdf/qrcode` 1.2.2 is now bundled in `lib/Mpdf/QrCode/` (data files in `lib/Mpdf/data/`) and listed in `lib/THIRD-PARTY.md`. License: LGPL-2.1-or-later per its composer.json (the LICENSE file is the LGPL-3.0 text).

## 0.1.0 (2026-10-15)

- First version: `write()`, `css()`, `renderTemplate()`, `show()`, `download()`, `save()`, `output()`, header/footer/title/author, fonts and icon fonts, direct access to mPDF via `$pdf->mpdf`.
- mPDF 8.3.1 bundled in `lib/` (no Composer needed).
- Module configuration page with explanations and examples, translatable (German, French, Spanish, Portuguese, Italian, Dutch as CSV in `languages/`).
  After installing or updating, use "install translations" in the module configuration.
- The data folder `site/assets/LePdf/` is protected with an `.htaccess` (Apache) so that saved PDFs are not reachable from the web.
