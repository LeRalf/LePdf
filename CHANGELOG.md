# Changelog

## Unreleased

- New: use an existing PDF as the base of a document. `open($file)` takes over all pages unchanged, `stampImage()` puts an image (for example a signature from a signature pad, as raw data, data URI or file) and `stampHtml()` an HTML box on a page. Header, footer and margins are not added to opened pages, and `write()` and `css()` throw an exception in this mode. Uses the FPDI importer that is already part of mPDF; PDFs with compressed cross-reference streams cannot be imported and throw an exception.
- Internal: path handling of `save()` moved to a shared helper, behaviour unchanged.

## 0.1.1 (2026-11-07)

- Fix: `<barcode type="QR">` failed with "Mpdf\QrCode package was not found". The package `mpdf/qrcode` 1.2.2 is now bundled in `lib/Mpdf/QrCode/` (data files in `lib/Mpdf/data/`) and listed in `lib/THIRD-PARTY.md`. License: LGPL-2.1-or-later per its composer.json (the LICENSE file is the LGPL-3.0 text).

## 0.1.0 (2026-10-15)

- First version: `write()`, `css()`, `renderTemplate()`, `show()`, `download()`, `save()`, `output()`, header/footer/title/author, fonts and icon fonts, direct access to mPDF via `$pdf->mpdf`.
- mPDF 8.3.1 bundled in `lib/` (no Composer needed).
- Module configuration page with explanations and examples, translatable (German, French, Spanish, Portuguese, Italian, Dutch as CSV in `languages/`).
  After installing or updating, use "install translations" in the module configuration.
- The data folder `site/assets/LePdf/` is protected with an `.htaccess` (Apache) so that saved PDFs are not reachable from the web.
