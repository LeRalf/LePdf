# Changelog

## 0.1.0 (2026-10-15)

- First version: `write()`, `css()`, `renderTemplate()`, `show()`, `download()`, `save()`, `output()`, header/footer/title/author, fonts and icon fonts, direct access to mPDF via `$pdf->mpdf`.
- mPDF 8.3.1 bundled in `lib/` (no Composer needed).
- Module configuration page with explanations and examples, translatable (German, French, Spanish, Portuguese, Italian, Dutch as CSV in `languages/`).
  After installing or updating, use "install translations" in the module configuration.
- The data folder `site/assets/LePdf/` is protected with an `.htaccess` (Apache) so that saved PDFs are not reachable from the web.
