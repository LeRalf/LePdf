<?php
// Minimal stand-ins for ProcessWire classes so the core logic can be tested without ProcessWire.
namespace ProcessWire {
	interface Module {}
	interface ConfigurableModule {}
	class WireException extends \Exception {}
	class InputfieldWrapper {
		public $children = [];
		public function add($child) { $this->children[] = $child; return $this; }
	}

	/** Generic stand-in for any Inputfield module: records attributes and properties. */
	class StubInputfield {
		public $type; public $attrs = []; public $options = []; public $props = []; public $children = [];
		public function __construct($type) { $this->type = $type; }
		public function attr($k, $v = null) { if(func_num_args() === 1) return isset($this->attrs[$k]) ? $this->attrs[$k] : null; $this->attrs[$k] = $v; return $this; }
		public function addOption($v, $l = null) { $this->options[$v] = $l; return $this; }
		public function add($child) { $this->children[] = $child; return $this; }
		public function __set($k, $v) { $this->props[$k] = $v; }
		public function __get($k) { return isset($this->props[$k]) ? $this->props[$k] : null; }
	}

	class StubModules {
		public function get($name) { return new StubInputfield($name); }
	}

	function wire($name) { return $GLOBALS['__stub_wire'][$name]; }

	/** Stand-in for ProcessWire's translation function: returns the text unchanged. */
	function __($text, $textdomain = null, $context = '') { return $text; }

	class WireData {
		protected $d = [];
		public function set($k, $v) { $this->d[$k] = $v; return $this; }
		public function get($k) { return isset($this->d[$k]) ? $this->d[$k] : null; }
		public function __get($k) { return $this->get($k); }
		public function wire($name = null) {
			if(is_object($name)) return $name;
			$c = $GLOBALS['__stub_wire'];
			return $c[$name];
		}
	}

	class TemplateFile {
		protected $file; protected $vars = [];
		public function __construct($file) { $this->file = $file; }
		public function set($k, $v) { $this->vars[$k] = $v; }
		public function render() { extract($this->vars); ob_start(); include $this->file; return ob_get_clean(); }
	}

	class StubFiles {
		public function mkdir($dir, $recursive = false) { return is_dir($dir) || mkdir($dir, 0775, $recursive); }
	}
}
