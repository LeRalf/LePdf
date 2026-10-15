<?php
// Usage: php tests/run.php
// Uses the bundled lib/ by default. Pass the path to another autoload.php as the first argument to test against it.
namespace ProcessWire;

require __DIR__ . '/stubs.php';

if(isset($argv[1])) require $argv[1];

$work = sys_get_temp_dir() . '/lepdf-test-' . getmypid();
@mkdir($work . '/assets', 0775, true);
@mkdir($work . '/templates', 0775, true);
$GLOBALS['__stub_wire'] = [
	'config' => (object) ['paths' => (object) [
		'assets' => $work . '/assets/',
		'templates' => $work . '/templates/',
		'root' => $work . '/',
	]],
	'files' => new StubFiles(),
	'modules' => new StubModules(),
];

require __DIR__ . '/../LePdf.module.php';

/** Test variant: captures headers and does not end the script. */
class TestPdf extends LePdf {
	public $sentHeaders = [];
	public $terminated = false;
	protected function sendHeaders(array $headers) { $this->sentHeaders = $headers; }
	protected function terminate() { $this->terminated = true; }
	protected function cleanBuffers() {}
	public function __call($name, $args) { return $this->$name(...$args); } // access to protected methods for tests
}

$failed = 0; $passed = 0;
function check($name, $cond, $info = '') {
	global $failed, $passed;
	if($cond) { $passed++; echo "  ok    $name\n"; }
	else { $failed++; echo "  FAIL  $name $info\n"; }
}
function throws(callable $fn, $needle = '') {
	try { $fn(); } catch(\Exception $e) { return $needle === '' || strpos($e->getMessage(), $needle) !== false; }
	return false;
}

echo "Output\n";
$p = new TestPdf();
$p->title('Test title')->author('Tester')->header('Header {PAGENO}')->footer('Footer');
$p->css('p { color: red; }')->write('<p>Hello world äöü ß €</p>');
$bytes = $p->output();
check('output() returns a PDF', strpos($bytes, '%PDF') === 0 && strlen($bytes) > 1000);
check('output() is repeatable', $p->output() === $bytes);
check('write() after output throws', throws(function() use ($p) { $p->write('x'); }, 'already been rendered'));
check('settings() after start throws', throws(function() use ($p) { $p->settings(['margin_top' => 5]); }, 'must be called before'));

$p = new TestPdf();
$p->write('<p>Page</p>');
ob_start();
$p->show('Résumé-Müller.pdf');
$sent = ob_get_clean();
check('show() sends PDF data', strpos($sent, '%PDF') === 0);
check('show() terminates', $p->terminated === true);
$h = implode("\n", $p->sentHeaders);
check('show(): inline', strpos($h, 'Content-Disposition: inline;') !== false);
check('show(): umlaut encoded', strpos($h, "filename*=UTF-8''R%C3%A9sum%C3%A9-M%C3%BCller.pdf") !== false, $h);
check('show(): ASCII fallback', strpos($h, 'filename="R_sum_-M_ller.pdf"') !== false, $h);
check('show(): Content-Length matches', strpos($h, 'Content-Length: ' . strlen($sent)) !== false);

$p = new TestPdf();
$p->write('<p>x</p>');
ob_start(); $p->download('a.pdf'); ob_end_clean();
check('download(): attachment', strpos(implode("\n", $p->sentHeaders), 'attachment;') !== false);

echo "File name\n";
$p = new TestPdf();
check('path is stripped', $p->cleanFilename('../../etc/passwd') === '_.._etc_passwd.pdf', $p->cleanFilename('../../etc/passwd'));
check('extension is added', $p->cleanFilename('report') === 'report.pdf');
check('empty name', $p->cleanFilename('') === 'document.pdf');
check('control characters and quotes removed', $p->cleanFilename("a\r\nb\"c.pdf") === 'abc.pdf', $p->cleanFilename("a\r\nb\"c.pdf"));

echo "Saving\n";
$p = new TestPdf();
$p->write('<p>x</p>');
$path = $p->save('sub/folder/test.pdf');
check('save() relative below assets/LePdf', $path === $work . '/assets/LePdf/sub/folder/test.pdf' && is_file($path));
check('saved file is a PDF', strpos(file_get_contents($path), '%PDF') === 0);
check('save() rejects ".."', throws(function() use ($p) { $p->save('../x.pdf'); }, '".."'));
check('save() rejects wrong extension', throws(function() use ($p) { $p->save('x.txt'); }, '.pdf'));
$abs = $p->save($work . '/abs.pdf');
check('save() absolute', $abs === $work . '/abs.pdf' && is_file($abs));
check('isAbsolute Windows backslash', $p->isAbsolute('D:\\x\\y.pdf'));
check('isAbsolute Windows slash', $p->isAbsolute('D:/x/y.pdf'));
check('isAbsolute Unix', $p->isAbsolute('/var/x.pdf'));
check('isAbsolute relative', !$p->isAbsolute('x/y.pdf'));

echo "Bundled fonts\n";
$p = new TestPdf();
$p->write('<p style="font-family: sans-serif">sans äöü ß €</p><p style="font-family: serif"><b>serif bold</b> <i>italic</i></p><p style="font-family: monospace">mono</p><p>default <b>bold</b></p>');
check('sans-serif, serif, monospace and default render', strpos($p->output(), '%PDF') === 0);

echo "Custom fonts\n";
$fontDir = $work . '/assets/LePdf/fonts/';
@mkdir($fontDir, 0775, true);
foreach(['OpenSans-Regular.ttf', 'OpenSans-Bold.ttf', 'Open Sans-Italic.ttf', 'Lonely-Bold.ttf', 'fa-solid-900.ttf', 'Serif_BoldItalic.otf', 'Serif-Regular.otf'] as $f) touch($fontDir . $f);
$p = new TestPdf();
$found = $p->scanFonts($fontDir);
check('family opensans with R, B, I', isset($found['opensans']) && $found['opensans'] == ['R' => 'OpenSans-Regular.ttf', 'B' => 'OpenSans-Bold.ttf', 'I' => 'Open Sans-Italic.ttf'], json_encode($found));
check('family without Regular is dropped', !isset($found['lonely']));
check('file without naming scheme is ignored', !isset($found['fasolid900']) && !isset($found['fa']));
check('otf and BoldItalic', isset($found['serif']['BI']) && $found['serif']['R'] === 'Serif-Regular.otf', json_encode($found));
foreach(glob($fontDir . '*') as $f) unlink($f);

// real font file: mPDF must accept and embed it (DejaVuSansMono is only embedded when actually used)
$ttf = __DIR__ . '/../lib/ttfonts/DejaVuSansMono.ttf';
if(is_file($ttf)) {
	copy($ttf, $fontDir . 'Mono2-Regular.ttf');
	$p = new TestPdf();
	$p->mpdf->SetCompression(false);
	$p->write('<p style="font-family: mono2">abc</p>');
	check('font from folder is embedded', strpos($p->output(), 'DejaVuSansMono') !== false);

	$p = new TestPdf();
	$p->iconFont('icn', 'Mono2-Regular.ttf');
	$p->mpdf->SetCompression(false);
	$p->write('<i style="font-family: icn">abc</i>');
	check('iconFont() is embedded (also for italic)', strpos($p->output(), 'DejaVuSansMono') !== false);
} else {
	echo "  skipped: test font not found\n";
}

echo "Configuration\n";
$p = new TestPdf();
$p->set('orientation', 'L')->set('format', 'A5');
$m = $p->mpdf;
check('format and orientation from configuration', abs($m->w - 210) < 1 && abs($m->h - 148) < 1, $m->w . 'x' . $m->h);
check('use_kwt and tableMinSizePriority from configuration', $m->use_kwt === true && $m->tableMinSizePriority === true);
$p = new TestPdf();
$p->set('use_kwt', 0);
check('use_kwt can be switched off', $p->mpdf->use_kwt === false);
$p = new TestPdf();
$p->settings(['margin_top' => 40]);
check('settings() takes effect', $p->mpdf->tMargin == 40, (string) $p->mpdf->tMargin);

echo "Protection of the data folder\n";
$base = $work . '/assets/LePdf/';
check('.htaccess created in the data folder', is_file($base . '.htaccess') && strpos(file_get_contents($base . '.htaccess'), 'Require all denied') !== false);
check('.htaccess covers old Apache too', strpos(file_get_contents($base . '.htaccess'), 'Deny from all') !== false);
file_put_contents($base . '.htaccess', "# my own rule\n");
$p = new TestPdf();
$p->mpdf;
check('an existing .htaccess is not overwritten', file_get_contents($base . '.htaccess') === "# my own rule\n");

echo "Template\n";
file_put_contents($work . '/templates/list.php', '<h1><?= htmlspecialchars($title) ?></h1><?php foreach($names as $n) echo "<p>$n</p>"; ?>');
$p = new TestPdf();
$html = $p->renderTemplate('list.php', ['title' => 'Attendees', 'names' => ['A', 'B']]);
check('renderTemplate() relative path', $html === '<h1>Attendees</h1><p>A</p><p>B</p>', $html);
check('renderTemplate() missing file', throws(function() use ($p) { $p->renderTemplate('missing.php'); }, 'not found'));

echo "Settings page and translations\n";
$moduleFile = __DIR__ . '/../LePdf.module.php';
$moduleSource = file_get_contents($moduleFile);
$moduleLines = explode("\n", $moduleSource);

// ProcessWire's language parser finds only one translation call per line and only literal strings
$strings = []; $multi = [];
foreach($moduleLines as $no => $line) {
	$count = preg_match_all('/(?:^|[\s.=(\\\\,])__\(\s*([\'"])(.+?)(?<!\\\\)\1/', $line, $m);
	if($count > 1) $multi[] = $no + 1;
	foreach($m[2] as $text) $strings[$text] = true;
}
check('translatable strings found in the module', count($strings) > 20, (string) count($strings));
check('at most one translation call per line', !$multi, implode(',', $multi));
check('no translation call with variables or concatenation', !preg_match('/\b__\(\s*[^\'"\s)]/', $moduleSource));

// CSV files in languages/ must be in the format of ProcessWire's own export
$csvFiles = glob(__DIR__ . '/../languages/*.csv');
check('translation files present', count($csvFiles) >= 5, (string) count($csvFiles));
foreach($csvFiles as $csvFile) {
	$code = basename($csvFile, '.csv');
	$fp = fopen($csvFile, 'r');
	$header = fgetcsv($fp, 8192, ',', '"', '');
	check("$code: header is en,$code,description,file,hash", $header === ['en', $code, 'description', 'file', 'hash'], json_encode($header));
	$rows = []; $badHash = []; $badFile = []; $empty = []; $badTokens = [];
	while(($r = fgetcsv($fp, 8192, ',', '"', '')) !== false) {
		if(count($r) !== 5) { $badHash[] = 'row length'; continue; }
		list($orig, $trans, , $file, $hash) = $r;
		$rows[$orig] = $trans;
		if($hash !== md5($orig)) $badHash[] = $orig;
		if($file !== 'site/modules/LePdf/LePdf.module.php') $badFile[] = $file;
		if(trim($trans) === '') $empty[] = $orig;
		// placeholders, code and option names must survive translation unchanged
		preg_match_all('/\{[A-Za-z]+[^}]*\}|->\w+\(\)|settings\(\[\.\.\.\]\)|\$pdf->mpdf|use_kwt|tableMinSizePriority|keep-with-table/', $orig, $a);
		foreach($a[0] as $token) if(strpos($trans, $token) === false) $badTokens[] = "$orig => $token";
	}
	fclose($fp);
	check("$code: hash is md5 of the original text", !$badHash, implode(' | ', $badHash));
	check("$code: file column", !$badFile, implode(',', $badFile));
	check("$code: no empty translations", !$empty, implode(' | ', $empty));
	check("$code: placeholders and code tokens preserved", !$badTokens, implode(' | ', $badTokens));
	$missing = array_diff(array_keys($strings), array_keys($rows));
	$extra = array_diff(array_keys($rows), array_keys($strings));
	check("$code: covers exactly the strings of the module", !$missing && !$extra, 'missing: ' . implode(' | ', $missing) . ' extra: ' . implode(' | ', $extra));
}

// structure of the generated settings page
$names = []; $widths = []; $collect = null;
$collect = function($node) use (&$collect, &$names, &$widths) {
	foreach($node->children as $child) {
		if($child->type === 'InputfieldFieldset') { $collect($child); continue; }
		$names[] = $child->attr('name');
		$widths[$child->attr('name')] = $child->columnWidth;
	}
};
$form = LePdf::getModuleConfigInputfields([]);
check('settings page: three fieldsets', count($form->children) === 3 && $form->children[0]->type === 'InputfieldFieldset');
$collect($form);
$expected = ['header', 'footer', 'author', 'format', 'orientation', 'use_kwt', 'table_min_size_priority'];
check('settings page: all fields present', $names === $expected, implode(',', $names));
check('settings page: fields share rows (column widths)', $widths['header'] == 50 && $widths['author'] == 34 && $widths['use_kwt'] == 50);
$header = $form->children[0]->children[0];
check('settings page: "Example:" in bold below the field', strpos($header->notes, '**Example:**') === 0 || strpos($header->notes, "\n**Example:**") !== false, $header->notes);
check('settings page: "Placeholders:" in bold', strpos($header->notes, '**Placeholders:**') === 0, $header->notes);
check('settings page: texts are plain (ProcessWire encodes them once)', strpos($header->notes, '<strong>') === false && strpos($header->notes, '&') === false && $header->entityEncodeText !== false);
check('settings page: description present', strlen($header->description) > 20);
$form = LePdf::getModuleConfigInputfields(['header' => 'X|Y|Z', 'use_kwt' => 0]);
check('settings page: stored values are shown', $form->children[0]->children[0]->attr('value') === 'X|Y|Z' && $form->children[2]->children[0]->attr('checked') === '');
check('settings page: special characters are not pre-escaped (no double encoding)', strpos($form->children[2]->children[0]->notes, '<h3 keep-with-table="1">') !== false, $form->children[2]->children[0]->notes);

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
