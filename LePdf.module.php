<?php namespace ProcessWire;

/**
 * LePdf - lean mPDF helper for ProcessWire
 *
 * Creates PDFs from HTML and delivers them: show in the browser, download,
 * save to disk, or return as a string. The mPDF instance stays directly
 * accessible at all times ($pdf->mpdf).
 *
 * Independent implementation. The basic idea (a thin wrapper around mPDF) is
 * inspired by RockPdf by Bernhard Baumrock (MIT).
 *
 * mPDF and its dependencies are bundled in lib/ (no Composer needed on the
 * server). See lib/THIRD-PARTY.md.
 *
 * @author Ralf Leichtle
 * @copyright 2026 Ralf Leichtle
 * @version 0.2.0 (2026-11-09)
 * @license MIT (this module); bundled libraries have their own licenses
 *
 * Compatible with PHP >= 7.4 (deliberately no PHP 8 only syntax).
 */
class LePdf extends WireData implements Module, ConfigurableModule {

	public static function getModuleInfo() {
		return [
			'title' => 'LePdf',
			'version' => '0.2.0',
			'summary' => 'Lean mPDF helper: create PDFs from HTML and show, download or save them.',
			'author' => 'Ralf Leichtle',
			'href' => 'https://github.com/LeRalf/LePdf',
			'singular' => false, // every $modules->get('LePdf') returns a new, empty PDF
			'autoload' => false,
			'requires' => 'ProcessWire>=3.0.0, PHP>=7.4.0',
			'icon' => 'file-pdf-o',
		];
	}

	/** @var \Mpdf\Mpdf|null */
	protected $instance;

	/** @var bool the PDF has been rendered, no more content can be added */
	protected $finalized = false;

	/** @var string|null cached PDF data */
	protected $bytes;

	/** @var array options for the mPDF constructor (see settings()) */
	protected $options = [];

	/** @var array custom fonts: family => [R=>, B=>, I=>, BI=>] */
	protected $customFonts = [];

	/** @var array title, header, footer, author set before the instance exists */
	protected $meta = [];

	/** @var string|null absolute path of an existing PDF that is the base of this document (see open()) */
	protected $source = null;

	/** @var array stamps queued for the opened PDF (see stampImage(), stampHtml()) */
	protected $stamps = [];

	/** @var bool the pages of the opened PDF have been imported and stamped */
	protected $sourceBuilt = false;

	public function __construct() {
		// defaults of the module configuration
		$this->set('header', '');
		$this->set('footer', '');
		$this->set('author', '');
		$this->set('format', 'A4');
		$this->set('orientation', 'P');
		$this->set('use_kwt', 1);
		$this->set('table_min_size_priority', 1);
	}

	public function init() {
	}

	/* ########## Access to mPDF ########## */

	/**
	 * Returns the mPDF instance (created on first call).
	 * Also available as a property: $pdf->mpdf
	 *
	 * @return \Mpdf\Mpdf
	 */
	public function mpdf() {
		if($this->instance) return $this->instance;

		$this->loadLibrary();
		$config = $this->buildConfig();

		try {
			$mpdf = new \Mpdf\Mpdf($config);
		} catch(\Mpdf\MpdfException $e) {
			throw new WireException('LePdf: ' . $e->getMessage(), 0, $e);
		}

		$mpdf->use_kwt = (bool) $this->get('use_kwt');
		$mpdf->tableMinSizePriority = (bool) $this->get('table_min_size_priority');

		$author = isset($this->meta['author']) ? $this->meta['author'] : $this->get('author');
		if($author !== '') $mpdf->SetAuthor($author);
		if(isset($this->meta['title'])) $mpdf->SetTitle($this->meta['title']);

		// an opened PDF keeps its own pages: no header or footer on top of them
		if($this->source === null) {
			$header = isset($this->meta['header']) ? $this->meta['header'] : $this->get('header');
			if($header !== '') $mpdf->SetHeader($header);
			$footer = isset($this->meta['footer']) ? $this->meta['footer'] : $this->get('footer');
			if($footer !== '') $mpdf->SetFooter($footer);
		}

		$this->instance = $mpdf;
		return $mpdf;
	}

	/**
	 * Makes $pdf->mpdf work as a property.
	 */
	public function get($key) {
		if($key === 'mpdf') return $this->mpdf();
		return parent::get($key);
	}

	/* ########## Settings (before any content) ########## */

	/**
	 * Set options for the mPDF constructor, for example ['margin_top' => 30].
	 * Must be called before mPDF is accessed for the first time.
	 *
	 * @param array $options
	 * @return $this
	 */
	public function settings(array $options) {
		$this->requireNotStarted('settings()');
		$this->options = array_merge($this->options, $options);
		return $this;
	}

	/**
	 * Register a custom font. File names are relative to site/assets/LePdf/fonts/.
	 * Styles that are not given stay empty.
	 *
	 * @return $this
	 */
	public function font($family, $regular, $bold = null, $italic = null, $boldItalic = null) {
		$this->requireNotStarted('font()');
		$styles = ['R' => $regular, 'B' => $bold, 'I' => $italic, 'BI' => $boldItalic];
		$this->customFonts[$this->familyKey($family)] = array_filter($styles, function($v) {
			return $v !== null && $v !== '';
		});
		return $this;
	}

	/**
	 * Register an icon font (for example FontAwesome): one file for all styles.
	 * Use in HTML: <i style="font-family: fas">&#xf118;</i>
	 *
	 * @return $this
	 */
	public function iconFont($family, $file) {
		return $this->font($family, $file, $file, $file, $file);
	}

	/** @return $this */
	public function header($text) {
		$this->meta['header'] = (string) $text;
		if($this->instance) $this->instance->SetHeader((string) $text);
		return $this;
	}

	/** @return $this */
	public function footer($text) {
		$this->meta['footer'] = (string) $text;
		if($this->instance) $this->instance->SetFooter((string) $text);
		return $this;
	}

	/** @return $this */
	public function title($text) {
		$this->meta['title'] = (string) $text;
		if($this->instance) $this->instance->SetTitle((string) $text);
		return $this;
	}

	/** @return $this */
	public function author($text) {
		$this->meta['author'] = (string) $text;
		if($this->instance) $this->instance->SetAuthor((string) $text);
		return $this;
	}

	/* ########## Content ########## */

	/**
	 * Write HTML into the PDF.
	 *
	 * @return $this
	 */
	public function write($html) {
		$this->requireNotFinalized();
		$this->requireNoSource('write()');
		$this->mpdf()->WriteHTML((string) $html);
		return $this;
	}

	/**
	 * Set document CSS (call before the first write()).
	 *
	 * @return $this
	 */
	public function css($css) {
		$this->requireNotFinalized();
		$this->requireNoSource('css()');
		$this->mpdf()->WriteHTML((string) $css, \Mpdf\HTMLParserMode::HEADER_CSS);
		return $this;
	}

	/**
	 * Render a PHP template and return the HTML as a string.
	 * Path is absolute or relative to site/templates/. Variables are available
	 * in the template under their names.
	 *
	 * @return string
	 */
	public function renderTemplate($file, array $vars = []) {
		if(!$this->isAbsolute($file)) $file = $this->wire('config')->paths->templates . ltrim($file, '/\\');
		if(!is_file($file)) throw new WireException("LePdf: template not found: $file");
		$template = new TemplateFile($file);
		$this->wire($template);
		foreach($vars as $key => $value) $template->set($key, $value);
		return $template->render();
	}

	/* ########## Existing PDFs: open and stamp ########## */

	/**
	 * Use an existing PDF as the base of this document instead of writing HTML. All pages are taken over
	 * unchanged; stampImage() and stampHtml() put something on top (for example a signature).
	 * The file is read when the PDF is rendered. Relative paths are resolved below site/assets/LePdf/.
	 * Header and footer are not added to the pages of an opened PDF, and write() and css() are not available.
	 * Never build the path from user input.
	 *
	 * Limit: the bundled importer (FPDI, free version) cannot read every PDF. PDFs created with LePdf work;
	 * a PDF that uses compressed cross-reference streams makes output(), save() and show() throw an exception.
	 *
	 * @param string $file
	 * @return $this
	 */
	public function open($file) {
		$this->requireNotStarted('open()');
		$path = $this->resolvePath($file);
		if(!is_file($path) || !is_readable($path)) throw new WireException("LePdf: cannot read PDF: $path");
		$this->source = $path;
		return $this;
	}

	/**
	 * Put an image on a page of the opened PDF, for example a signature.
	 *
	 * $image is a PNG, JPEG or GIF: raw image data, a "data:image/png;base64,..." string (as a signature pad
	 * delivers it) or the path of a local file (relative paths below site/assets/LePdf/). Web addresses are not loaded.
	 * $x, $y, $width and $height are in mm from the top left corner of the page; $height 0 keeps the aspect ratio.
	 * $page counts from 1; 0 is the last page.
	 *
	 * @return $this
	 */
	public function stampImage($image, $x, $y, $width, $height = 0, $page = 0) {
		$this->requireOpened('stampImage()');
		$this->requireNotFinalized();
		$this->stamps[] = [
			'type' => 'image',
			'data' => $this->imageData($image),
			'x' => (float) $x, 'y' => (float) $y, 'w' => (float) $width, 'h' => (float) $height,
			'page' => $this->checkPage($page),
		];
		return $this;
	}

	/**
	 * Put an HTML box on a page of the opened PDF, for example "Signed on ...".
	 * Box position and size in mm from the top left corner of the page; text that does not fit stays visible.
	 * Like write(), the HTML is used as it is: escape data with $sanitizer->entities().
	 * $page counts from 1; 0 is the last page.
	 *
	 * @return $this
	 */
	public function stampHtml($html, $x, $y, $width, $height, $page = 0) {
		$this->requireOpened('stampHtml()');
		$this->requireNotFinalized();
		$this->stamps[] = [
			'type' => 'html',
			'html' => (string) $html,
			'x' => (float) $x, 'y' => (float) $y, 'w' => (float) $width, 'h' => (float) $height,
			'page' => $this->checkPage($page),
		];
		return $this;
	}

	/**
	 * Imports every page of the opened PDF and paints the queued stamps on it.
	 */
	protected function buildFromSource() {
		if($this->sourceBuilt) return;
		$data = file_get_contents($this->source); // read at once: no file handle stays open, the file can be overwritten by save()
		if($data === false || strpos($data, '%PDF') !== 0) {
			throw new WireException('LePdf: not a PDF file: ' . $this->source);
		}
		$mpdf = $this->mpdf();
		$count = $mpdf->setSourceFile(\setasign\Fpdi\PdfParser\StreamReader::createByString($data));
		foreach($this->stamps as $s) {
			if($s['page'] > $count) {
				throw new WireException("LePdf: the PDF has $count page(s), page {$s['page']} does not exist.");
			}
		}
		for($i = 1; $i <= $count; $i++) {
			$tpl = $mpdf->importPage($i);
			$size = $mpdf->getTemplateSize($tpl);
			// mPDF takes the sheet size as a portrait format and turns it for 'L': give it short side first
			$mpdf->AddPageByArray([
				'orientation' => $size['orientation'],
				'sheet-size' => [min($size['width'], $size['height']), max($size['width'], $size['height'])],
				'margin-left' => 0, 'margin-right' => 0, 'margin-top' => 0, 'margin-bottom' => 0,
				'margin-header' => 0, 'margin-footer' => 0,
			]);
			$mpdf->useTemplate($tpl);
			foreach($this->stamps as $s) {
				if(($s['page'] === 0 ? $count : $s['page']) !== $i) continue;
				if($s['type'] === 'image') {
					$name = 'lepdf' . md5($s['data']);
					$mpdf->imageVars[$name] = $s['data'];
					$mpdf->Image('var:' . $name, $s['x'], $s['y'], $s['w'], $s['h'], '', '', true, false);
				} else {
					$mpdf->WriteFixedPosHTML($s['html'], $s['x'], $s['y'], $s['w'], $s['h'], 'visible');
				}
			}
		}
		$this->sourceBuilt = true;
	}

	/**
	 * Raw image data from an image argument (data URI, raw data or file path). Only PNG, JPEG and GIF.
	 *
	 * @return string
	 */
	protected function imageData($image) {
		$image = (string) $image;
		if(strpos($image, 'data:image/') === 0) {
			if(!preg_match('#^data:image/(?:png|jpe?g|gif);base64,([A-Za-z0-9+/=\s]+)$#', $image, $m)) {
				throw new WireException('LePdf: invalid image data (a PNG, JPEG or GIF as base64 data URI is expected).');
			}
			$data = base64_decode(preg_replace('/\s+/', '', $m[1]), true);
			if($data === false) throw new WireException('LePdf: invalid base64 image data.');
		} elseif(@getimagesizefromstring($image) !== false) {
			$data = $image;
		} else {
			$path = $this->resolvePath($image);
			if(!is_file($path) || !is_readable($path)) throw new WireException("LePdf: cannot read image: $path");
			$data = file_get_contents($path);
			if($data === false) throw new WireException("LePdf: cannot read image: $path");
		}
		if(strlen($data) > 5 * 1024 * 1024) throw new WireException('LePdf: image is larger than 5 MB.');
		$info = @getimagesizefromstring($data);
		if($info === false || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_GIF], true)) {
			throw new WireException('LePdf: the image must be a PNG, JPEG or GIF.');
		}
		return $data;
	}

	/** @return int */
	protected function checkPage($page) {
		$page = (int) $page;
		if($page < 0) throw new WireException('LePdf: the page number must be 1 or higher (0 is the last page).');
		return $page;
	}

	/* ########## Output ########## */

	/**
	 * PDF data as a string.
	 *
	 * @return string
	 */
	public function output() {
		if($this->bytes !== null) return $this->bytes;
		try {
			if($this->source !== null) $this->buildFromSource();
			$bytes = $this->mpdf()->Output('', \Mpdf\Output\Destination::STRING_RETURN);
		} catch(WireException $e) {
			throw $e;
		} catch(\Mpdf\MpdfException $e) {
			throw new WireException('LePdf: ' . $e->getMessage(), 0, $e);
		} catch(\setasign\Fpdi\FpdiException $e) {
			throw new WireException('LePdf: the PDF cannot be imported (' . $e->getMessage() . '). The bundled importer does not read every PDF, for example not those with compressed cross-reference streams.', 0, $e);
		}
		$this->finalized = true;
		return $this->bytes = $bytes;
	}

	/**
	 * Show the PDF in the browser and end the script.
	 */
	public function show($filename = 'document.pdf') {
		$this->send($filename, 'inline');
	}

	/**
	 * Send the PDF as a download and end the script.
	 */
	public function download($filename = 'document.pdf') {
		$this->send($filename, 'attachment');
	}

	/**
	 * Save the PDF to a file. Relative paths are resolved below site/assets/LePdf/.
	 *
	 * @return string absolute path of the file
	 */
	public function save($path = 'document.pdf') {
		$path = $this->resolvePath($path);
		if(strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'pdf') {
			throw new WireException('LePdf: file extension must be .pdf');
		}
		$dir = dirname($path);
		if(!is_dir($dir) && !$this->wire('files')->mkdir($dir, true)) {
			throw new WireException("LePdf: cannot create directory: $dir");
		}
		$bytes = $this->output();
		if(file_put_contents($path, $bytes) === false) {
			throw new WireException("LePdf: cannot write file: $path");
		}
		return $path;
	}

	/* ########## Internal: sending ########## */

	protected function send($filename, $disposition) {
		$bytes = $this->output(); // render first so errors stay visible
		$this->cleanBuffers();
		$this->sendHeaders($this->buildHeaders($filename, $disposition, strlen($bytes)));
		echo $bytes;
		$this->terminate();
	}

	/**
	 * Builds the HTTP headers (pure, no side effects).
	 *
	 * @return array
	 */
	protected function buildHeaders($filename, $disposition, $length) {
		$filename = $this->cleanFilename($filename);
		$ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename);
		return [
			'Content-Type: application/pdf',
			'Content-Length: ' . (int) $length,
			'Content-Disposition: ' . $disposition . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($filename),
			'Cache-Control: private, max-age=0, must-revalidate',
			'X-Content-Type-Options: nosniff',
		];
	}

	/**
	 * Clean a file name: no paths, no control characters, ends with .pdf.
	 *
	 * @return string
	 */
	protected function cleanFilename($filename) {
		$filename = str_replace(['\\', '/'], '_', (string) $filename);
		$filename = preg_replace('/[\x00-\x1F\x7F"]+/', '', $filename);
		$filename = trim($filename, ". \t");
		if($filename === '') $filename = 'document';
		if(strtolower(substr($filename, -4)) !== '.pdf') $filename .= '.pdf';
		return $filename;
	}

	protected function sendHeaders(array $headers) {
		if(headers_sent($file, $line)) {
			throw new WireException("LePdf: headers were already sent (output in $file:$line).");
		}
		foreach($headers as $h) header($h);
	}

	protected function cleanBuffers() {
		while(ob_get_level() > 0) ob_end_clean();
	}

	protected function terminate() {
		exit;
	}

	/* ########## Internal: configuration, fonts, directories ########## */

	protected function buildConfig() {
		$fontDir = $this->getBaseDir() . 'fonts/';
		$tmpDir = $this->getBaseDir() . 'tmp/';
		foreach([$fontDir, $tmpDir] as $dir) {
			if(!is_dir($dir) && !$this->wire('files')->mkdir($dir, true)) {
				throw new WireException("LePdf: cannot create directory: $dir");
			}
		}
		if(!is_writable($tmpDir)) throw new WireException("LePdf: directory is not writable: $tmpDir");
		$this->protectDirectory($this->getBaseDir());

		$configDefaults = (new \Mpdf\Config\ConfigVariables())->getDefaults();
		$fontDefaults = (new \Mpdf\Config\FontVariables())->getDefaults();

		// custom fonts take precedence over the ones shipped with mPDF
		$fontdata = $this->customFonts + $this->scanFonts($fontDir) + $fontDefaults['fontdata'];

		$config = [
			'mode' => 'utf-8',
			'format' => $this->get('format'),
			'orientation' => $this->get('orientation'),
			'tempDir' => $tmpDir,
			'fontDir' => array_merge($configDefaults['fontDir'], [$fontDir]),
			'fontdata' => $fontdata,
		];
		// the pages of an opened PDF are taken over as they are: no margins
		if($this->source !== null) {
			$config = array_merge($config, [
				'margin_left' => 0, 'margin_right' => 0, 'margin_top' => 0, 'margin_bottom' => 0,
				'margin_header' => 0, 'margin_footer' => 0,
			]);
		}
		return array_merge($config, $this->options);
	}

	/**
	 * Finds font files named Name-Regular.ttf, Name-Bold.ttf, Name-Italic.ttf,
	 * Name-BoldItalic.ttf (also .otf). In HTML the family is lower case without
	 * spaces, for example "Open Sans" becomes opensans.
	 *
	 * @return array
	 */
	public function scanFonts($dir) {
		$found = [];
		$files = is_dir($dir) ? scandir($dir) : [];
		foreach($files as $file) {
			if(!preg_match('/^(.+?)[-_ ]?(BoldItalic|BoldOblique|Bold|Italic|Oblique|Regular)\.(ttf|otf)$/i', $file, $m)) continue;
			$family = $this->familyKey($m[1]);
			if($family === '') continue;
			$style = strtolower($m[2]);
			if($style === 'regular') $key = 'R';
			elseif($style === 'bold') $key = 'B';
			elseif($style === 'italic' || $style === 'oblique') $key = 'I';
			else $key = 'BI';
			$found[$family][$key] = $file;
		}
		// families without a regular style are unusable for mPDF
		return array_filter($found, function($styles) {
			return isset($styles['R']);
		});
	}

	protected function familyKey($family) {
		return strtolower(preg_replace('/[^A-Za-z0-9]+/', '', (string) $family));
	}

	/**
	 * Keeps the generated files away from the web: PDFs saved with save() often contain personal data
	 * and mPDF temp files are of no use to anybody. Apache only; other web servers need their own rule.
	 * An existing .htaccess is never overwritten, so you can adjust it.
	 */
	protected function protectDirectory($dir) {
		$file = $dir . '.htaccess';
		if(is_file($file)) return;
		$rules = "# Created by LePdf: no direct web access to generated files.\n"
			. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
			. "<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n";
		file_put_contents($file, $rules);
	}

	protected function getBaseDir() {
		return $this->wire('config')->paths->assets . 'LePdf/';
	}

	/**
	 * Absolute paths are used as they are, relative paths are resolved below site/assets/LePdf/ ("..": not allowed).
	 *
	 * @return string
	 */
	protected function resolvePath($path) {
		$path = (string) $path;
		if($this->isAbsolute($path)) return $path;
		if(preg_match('#(^|[\\\\/])\.\.([\\\\/]|$)#', $path)) {
			throw new WireException('LePdf: ".." is not allowed in relative paths.');
		}
		return $this->getBaseDir() . ltrim($path, '/\\');
	}

	protected function isAbsolute($path) {
		return (bool) preg_match('#^([A-Za-z]:[\\\\/]|[\\\\/])#', (string) $path);
	}

	protected function loadLibrary() {
		if(class_exists('\Mpdf\Mpdf')) return; // already loaded or reachable through an autoloader
		$candidates = [
			__DIR__ . '/lib/autoload.php',     // bundled copy, no Composer needed
			__DIR__ . '/vendor/autoload.php',  // Composer install in the module folder
			$this->wire('config')->paths->root . 'vendor/autoload.php', // Composer in the site root
		];
		foreach($candidates as $file) {
			if(is_file($file)) {
				require_once $file;
				if(class_exists('\Mpdf\Mpdf')) return;
			}
		}
		throw new WireException('LePdf: mPDF not found. The bundled lib/ folder is missing or incomplete.');
	}

	protected function requireNotStarted($what) {
		if($this->instance) {
			throw new WireException("LePdf: $what must be called before mPDF is accessed or write() is used.");
		}
	}

	protected function requireOpened($what) {
		if($this->source === null) throw new WireException("LePdf: $what needs open() first.");
	}

	protected function requireNoSource($what) {
		if($this->source !== null) throw new WireException("LePdf: $what cannot be used together with open().");
	}

	protected function requireNotFinalized() {
		if($this->finalized) {
			throw new WireException('LePdf: the PDF has already been rendered. Call $modules->get(\'LePdf\') again for a new document.');
		}
	}

	/* ########## Module configuration ########## */

	/*
	 * Texts of the settings page are translatable with ProcessWire's own multi-language tools.
	 * Rules of the ProcessWire language parser: one translation call per line, literal strings only
	 * (no variables, no concatenation), no quotes of the same kind inside the text.
	 * Translations are shipped as CSV files in languages/ (see README).
	 */
	public static function getModuleConfigInputfields(array $data) {
		$modules = wire('modules');
		$value = function($name, $default) use ($data) {
			return isset($data[$name]) ? $data[$name] : $default;
		};

		// ProcessWire entity-encodes description and notes itself, so the texts are passed as plain text.
		// Bold uses ProcessWire's basic markdown (**text**); notes are joined with a newline.
		$decorate = function($f, $label, $description, array $notes, $width) {
			$f->label = $label;
			if($description !== '') $f->description = $description;
			if($notes) $f->notes = implode("\n", $notes);
			$f->columnWidth = $width;
			return $f;
		};
		$exampleLabel = __('Example:');
		$example = function($text) use ($exampleLabel) {
			return '**' . $exampleLabel . '** ' . $text;
		};
		$placeholdersLabel = __('Placeholders:');
		$placeholdersText = __('{PAGENO} = page number, {nbpg} = total pages, {DATE j.n.Y} = date.');
		$placeholders = '**' . $placeholdersLabel . '** ' . $placeholdersText;

		$fieldset = function($label, $description) use ($modules) {
			$f = $modules->get('InputfieldFieldset');
			$f->label = $label;
			$f->description = $description;
			$f->collapsed = 0;
			return $f;
		};
		$text = function($name, $default) use ($modules, $value) {
			$f = $modules->get('InputfieldText');
			$f->attr('name', $name);
			$f->attr('value', $value($name, $default));
			return $f;
		};
		$select = function($name, array $options, $default) use ($modules, $value) {
			$f = $modules->get('InputfieldSelect');
			$f->attr('name', $name);
			foreach($options as $optionValue => $title) $f->addOption($optionValue, $title);
			$f->attr('value', $value($name, $default));
			return $f;
		};
		$check = function($name, $default, $label) use ($modules, $value) {
			$f = $modules->get('InputfieldCheckbox');
			$f->attr('name', $name);
			$f->attr('value', 1);
			$f->attr('checked', $value($name, $default) ? 'checked' : '');
			$f->label2 = $label;
			return $f;
		};
		$enabled = __('Enabled');

		$fields = new InputfieldWrapper();

		// ---- Header and footer (two columns)
		$group = $fieldset(
			__('Header and footer'),
			__('Default texts at the top and bottom of every page. Code can replace them for a single PDF with ->header() and ->footer().')
		);
		$group->add($decorate(
			$text('header', ''),
			__('Default header'),
			__('Printed at the top of every page. Leave empty for no header. Three parts separated by a vertical bar: left|center|right.'),
			[$placeholders, $example(__('My Company|Attendance list|{DATE d.m.Y}'))],
			50
		));
		$group->add($decorate(
			$text('footer', ''),
			__('Default footer'),
			__('Printed at the bottom of every page. Leave empty for no footer. Same format as the header.'),
			[$placeholders, $example(__('Page {PAGENO} of {nbpg}'))],
			50
		));
		$fields->add($group);

		// ---- Document (three columns)
		$group = $fieldset(
			__('Document'),
			__('Defaults for new PDFs. Code can override them with settings([...]) and ->author().')
		);
		$group->add($decorate(
			$text('author', ''),
			__('Default author'),
			__('Stored in the PDF properties (PDF viewers show it under document properties).'),
			[$example(__('My Company'))],
			34
		));
		$group->add($decorate(
			$select('format', ['A3' => 'A3', 'A4' => 'A4', 'A5' => 'A5', 'Letter' => 'Letter', 'Legal' => 'Legal'], 'A4'),
			__('Paper format'),
			__('Page size of new PDFs.'),
			[$example(__('A4 is the standard in Europe, Letter in the USA.'))],
			33
		));
		$portrait = __('Portrait');
		$landscape = __('Landscape');
		$group->add($decorate(
			$select('orientation', ['P' => $portrait, 'L' => $landscape], 'P'),
			__('Orientation'),
			__('Upright (portrait) or sideways (landscape).'),
			[$example(__('Landscape suits wide tables with many columns.'))],
			33
		));
		$fields->add($group);

		// ---- Tables (two columns)
		$group = $fieldset(
			__('Tables'),
			__('Default behaviour of tables in new PDFs. Code can change both options on the mPDF object ($pdf->mpdf).')
		);
		$group->add($decorate(
			$check('use_kwt', 1, $enabled),
			__('Keep elements together with the table below'),
			__('Switches on the mPDF attribute keep-with-table (use_kwt). An element marked with it, for example a heading, stays on the same page as the table that follows it. Without the attribute nothing changes.'),
			[$example(__('<h3 keep-with-table="1">Participants</h3> followed by a table: if the table moves to the next page, the heading moves with it.'))],
			50
		));
		$group->add($decorate(
			$check('table_min_size_priority', 1, $enabled),
			__('Very large tables: allow page break'),
			__('What happens with a table that is higher than one page (mPDF option tableMinSizePriority). On: the table continues on the next page. Off: mPDF moves it to a new page and shrinks it to fit.'),
			[$example(__('A list with 200 rows runs over several pages instead of being squeezed onto one.'))],
			50
		));
		$fields->add($group);

		return $fields;
	}
}
