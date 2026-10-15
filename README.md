# LePdf

A lean mPDF helper for ProcessWire. HTML in, PDF out: show it in the browser, download it, save it, or get it as a string.
The mPDF instance stays directly accessible at all times.

The basic idea (a thin wrapper around mPDF) is inspired by RockPdf by Bernhard Baumrock. The code is an independent implementation.

Repository: https://github.com/LeRalf/LePdf

Status: version 0.1.0, early. Tested manually with PHP 8.5 on shared hosting; the automated tests run on PHP 8.3.

## Requirements

- PHP 7.4 or newer (the bundled mPDF 8.3 declares support up to PHP 8.5)
- ProcessWire 3.0 or newer
- PHP extensions `mbstring` and `gd` (required by mPDF); `zlib` is recommended (compressed PDFs)

No Composer is needed. mPDF and its dependencies are bundled in `lib/` (see `lib/THIRD-PARTY.md`).

## Installation

1. Copy the folder to `site/modules/LePdf/` (the folder must be called `LePdf`). On the server you need `LePdf.module.php`, `lib/` and `languages/`. `tests/`, `tools/` and the documentation are only for development.
2. In the admin: Modules, Refresh, install **LePdf**.
3. Optional: set defaults in the module configuration (header, footer, author, paper format, orientation, table behaviour). Every field has an explanation and an example below it.

The module creates `site/assets/LePdf/fonts/` (your own fonts) and `site/assets/LePdf/tmp/` (mPDF temp files) when needed.
Both are runtime data and should not be part of your repository.
It also puts an `.htaccess` into `site/assets/LePdf/` that blocks direct web access (Apache; an existing `.htaccess` is left alone). On other web servers, for example nginx, block that folder yourself.

If your project already loads mPDF through its own Composer autoloader, LePdf uses that and ignores `lib/`.

## Quick start

```php
$pdf = $modules->get('LePdf');          // every get() returns a new, empty PDF
$pdf->write('<h1>Hello</h1><p>This is a PDF.</p>');
$pdf->show('hello.pdf');                // show in the browser and end the script
```

`show()` and `download()` discard open output buffers first and then end the script.

## Methods

| Method | Purpose |
|---|---|
| `write($html)` | Write HTML into the PDF (can be called repeatedly) |
| `css($css)` | Set document CSS, before the first `write()` |
| `renderTemplate($file, $vars)` | Render a PHP template to an HTML string; path relative to `site/templates/` |
| `show($filename)` | Show in the browser, ends the script |
| `download($filename)` | Send as a download, ends the script |
| `save($path)` | Write to a file, returns the absolute path |
| `output()` | PDF data as a string |
| `title()`, `author()`, `header()`, `footer()` | Document properties, override the configuration |
| `settings([...])` | mPDF constructor options, e.g. `['margin_top' => 30]`, before any content |
| `font($family, $regular, $bold, $italic, $boldItalic)` | Register a custom font |
| `iconFont($family, $file)` | Register an icon font (FontAwesome) |
| `$pdf->mpdf` | The mPDF instance for everything else |

`write()`, `css()`, `title()`, `author()`, `header()`, `footer()`, `settings()`, `font()` and `iconFont()` can be chained.

`save()` with a relative path writes below `site/assets/LePdf/`; `..` is not allowed there. Absolute paths (Linux and Windows) are used as they are, and an existing file is overwritten. Never build the path from user input.

## Examples

### Attendance list with a signature column

```php
<?php namespace ProcessWire;

// check access before anything is generated
if(!$user->isLoggedin() || !$user->hasRole('editor')) throw new Wire404Exception();

// example data: an event page with a page field "participants" that holds users
$event = $pages->get((int) $input->get->id);

$rows = '';
foreach($event->participants->sort('name') as $u) {
	$rows .= '<tr><td>' . $sanitizer->entities($u->name) . '</td><td></td><td></td></tr>';
}

$pdf = $modules->get('LePdf');
$pdf->title("Attendance {$event->title}");
$pdf->css('td, th { border: 1px solid #000; padding: 4mm; } th { background: #eee; }');
$pdf->write('<h2>' . $sanitizer->entities($event->title) . "</h2>
<table width='100%' style='border-collapse: collapse'>
<tr><th>Name</th><th width='15%'>Present</th><th width='35%'>Signature</th></tr>
$rows
</table>");
$pdf->show('attendance.pdf');
```

### One PDF per person

```php
foreach($people as $person) {
	$pdf = $modules->get('LePdf');
	$pdf->write($pdf->renderTemplate('pdf/schedule.php', ['person' => $person]));
	$pdf->save("schedules/{$person->name}.pdf");
}
```

### Direct access to mPDF

```php
$pdf = $modules->get('LePdf');
$mpdf = $pdf->mpdf;                    // plain mPDF
$mpdf->SetProtection(['print']);
$mpdf->WriteHTML($html);
$pdf->show('file.pdf');
```

## Saved files

`save('schedules/anna.pdf')` writes below `site/assets/LePdf/` and returns the absolute path, for example `/path/to/site/assets/LePdf/schedules/anna.pdf`.
Missing folders are created. An existing file with the same name is overwritten.

Saved files are not reachable by URL (the folder is protected, see Installation). You reach them from PHP, and you hand them to a user from a template that checks the access first:

```php
// create and remember the file (store only the relative name, for example in a page field)
$pdf = $modules->get('LePdf');
$pdf->write($html);
$pdf->save("schedules/{$person->id}.pdf");

// later, in a template that delivers the file
if(!$user->isLoggedin() || $user->id !== $person->id) throw new Wire404Exception(); // your own access rule
$file = $config->paths->assets . "LePdf/schedules/{$person->id}.pdf";
if(!is_file($file)) throw new Wire404Exception();
wireSendFile($file, ['forceDownload' => false]);   // shown in the browser; true forces a download
```

`wireSendFile()` is a ProcessWire function. LePdf deletes nothing; remove old files yourself, for example with `$files->unlink()`.
If you want the files somewhere else, pass an absolute path to `save()`, for example a folder outside the web root if your hosting allows it.

## Fonts

mPDF ships with the DejaVu fonts, which cover most European languages. For other fonts put `.ttf` or `.otf` files into `site/assets/LePdf/fonts/`.
Files named `Name-Regular.ttf`, `Name-Bold.ttf`, `Name-Italic.ttf`, `Name-BoldItalic.ttf` are registered automatically.
In HTML the family is lower case without spaces: `OpenSans-Regular.ttf` becomes `font-family: opensans`. A family without a `-Regular` file is ignored.

Icon fonts such as FontAwesome have no style variants and are registered by hand, before the first content:

```php
$pdf = $modules->get('LePdf');
$pdf->iconFont('far', 'fa-regular-400.ttf');
$pdf->iconFont('fas', 'fa-solid-900.ttf');
$pdf->write('<i style="font-family: fas">&#xf118;</i> Hello');
```

## Notes

- **Security:** `write()` takes HTML as it is and does not escape anything. Escape data with `$sanitizer->entities()`. mPDF loads images and other resources that the HTML refers to, so never pass HTML from untrusted sources to it.
- **Files on disk:** the PDFs you create with `save()` are not reachable from the web by default (see Installation). If the PDFs contain personal data, keep it that way and deliver them through a template that checks the user's access, for example with `show()` or `download()`.
- **Access control** belongs in your template. LePdf does not check permissions. Always verify that the user may see the data before you build the PDF, especially with parameters such as `?id=`.
- After `output()`, `show()`, `download()` or `save()` the PDF is final. Further `write()` calls throw an exception. For a new document call `$modules->get('LePdf')` again.
- `use_kwt` and `tableMinSizePriority` are enabled by default in the module configuration. mPDF itself has both switched off.
  `use_kwt` only affects elements that carry the attribute `keep-with-table` (for example `<h3 keep-with-table="1">`).
- **Header and margins:** the header is placed relative to the edge of the page (mPDF `margin_header`, default 9 mm), not relative to the content. If `margin_top` is too small for the header, header and content overlap. 25 mm worked for a one-line header in our tests, for example `settings(['margin_top' => 25])`. The same applies to `margin_bottom` and the footer.
- Header and footer texts use mPDF syntax: `left|center|right` with the placeholders `{PAGENO}`, `{nbpg}` and `{DATE j.n.Y}`.

## Translations

The texts of the module configuration page are translatable with ProcessWire's own multi-language tools.
Translations for German, French, Spanish, Portuguese, Italian and Dutch are shipped as CSV files in `languages/` (`de.csv`, `fr.csv`, `es.csv`, `pt.csv`, `it.csv`, `nl.csv`).
The translations were created with AI assistance and have not been reviewed by native speakers. Corrections are welcome.

To use them (ProcessWire 3.0.181 or newer, multi-language support installed, the languages created):

1. Open the LePdf module configuration.
2. In the fieldset "Module Information", row "Languages", click "install translations".
3. Match each translation to the language of your site and submit.

The translations take effect immediately. After a LePdf update, run "install translations" again if `CHANGELOG.md` says the translations changed.
Only the configuration page is translated. Error messages and this documentation stay in English.

To add a language: create it in ProcessWire, then Setup, Languages, your language, Site files, "Find files to translate", select `LePdf.module.php`, translate, and download the CSV from the translation screen.
Save it as `languages/<code>.csv`. Details: [Add multi-language translations to your module](https://processwire.com/docs/modules/development/multi-language-translations/).
`php tests/run.php` checks that the shipped CSV files are complete and that placeholders such as `{PAGENO}` survive.

Rules for the module code (ProcessWire's language parser): one translation call per line, literal strings only, no variables or concatenation inside the text.

## Folder layout and updating the bundled libraries

`lib/` is generated and must not be edited by hand. Every PHP namespace has a folder of the same name
(`Mpdf\` is `lib/Mpdf/`). `lib/data/` and `lib/ttfonts/` belong to mPDF and must stay next to `lib/Mpdf/`.
Versions and licenses are listed in `lib/THIRD-PARTY.md`.

```
php tools/build-lib.php --check    show newer releases (changes nothing)
php tools/build-lib.php            rebuild lib/ from the versions pinned in the script
php tests/run.php                  run the tests
```

To update a package, change its `tag` in `tools/build-lib.php`, rebuild, and run the tests. The script needs the PHP CLI and `git`.
Some packages are held on an older release series on purpose because newer ones need PHP 8 (reasons are in the script).

## Tests

```
php tests/run.php
```

Runs without ProcessWire (stand-ins in `tests/stubs.php`) and uses the bundled `lib/`. The automated tests do not cover a real ProcessWire installation; that part was tested by hand (output, header/footer, templates, settings page).

## License

LePdf itself is MIT licensed, see `LICENSE`. The bundled libraries have their own licenses, see `lib/THIRD-PARTY.md` and `lib/licenses/`.
In particular mPDF is GPL-2.0-only. Because this repository ships mPDF in `lib/`, the repository as a whole is distributed under the terms of the GPL-2.0 as far as `lib/` is concerned; the LePdf code (`LePdf.module.php`) stays MIT and works on its own with a Composer copy of mPDF.
