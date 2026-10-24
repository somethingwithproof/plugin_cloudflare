<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2026 Thomas Vincent                                       |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cloudflare plugin for Cacti                                             |
 | https://github.com/somethingwithproof/plugin_cloudflare                 |
 +-------------------------------------------------------------------------+
*/

/*
 * Recording stand-ins for the Cacti 1.2.31 functions the plugin glue calls.
 * Each signature is copied from the release/1.2.31 tag (file:line beside
 * it) so a call that would fail against real Cacti fails here too. Every
 * definition is guarded, so loading this next to real Cacti changes nothing.
 *
 * State lives in $GLOBALS['cloudflare_glue']; tests reset it through
 * cloudflare_glue_reset().
 */

if (!defined('MESSAGE_LEVEL_NONE')) {
	define('MESSAGE_LEVEL_NONE', 0);
	define('MESSAGE_LEVEL_INFO', 1);
	define('MESSAGE_LEVEL_WARN', 2);
	define('MESSAGE_LEVEL_ERROR', 3);
}

if (!defined('POLLER_VERBOSITY_NONE')) {
	define('POLLER_VERBOSITY_NONE', 1);
	define('POLLER_VERBOSITY_LOW', 2);
	define('POLLER_VERBOSITY_MEDIUM', 3);
	define('POLLER_VERBOSITY_HIGH', 4);
	define('POLLER_VERBOSITY_DEBUG', 5);
	define('POLLER_VERBOSITY_DEVDBG', 6);
}

function cloudflare_glue_reset(): void {
	$GLOBALS['cloudflare_glue'] = array(
		'calls'            => array(),
		'settings'         => array(),
		'cached'           => array(),
		'cell'             => false,
		'realm'            => true,
		'connect'          => false,
		'enabled'          => true,
		'page'             => 'index.php',
		'on_log'           => null,
		'on_top'           => null,
		'import'           => array(),
		'hosts'            => array(),
		'next_host'        => 1,
		'invalid'          => false,
		'template_queries' => array(),
		'host_queries'     => array(),
		'remove_fails'     => false,
		'index_type'       => 'tunnelId',
		'data_template_id' => 0,
		'graph_local'      => array(),
		'data_local'       => array(),
		'next_graph'       => 1,
		'next_data'        => 1,
		'pushed'           => array(),
		'reindex'          => true,
	);
	$GLOBALS['cloudflare_glue_enabled_cache'] = array();
}

/**
 * @param array<int, mixed> $args
 */
function cloudflare_glue_record(string $function, array $args): void {
	if (!isset($GLOBALS['cloudflare_glue'])) {
		cloudflare_glue_reset();
	}

	$GLOBALS['cloudflare_glue']['calls'][] = array($function, $args);
}

/**
 * @return array<int, array<int, mixed>> argument lists of every call to $function
 */
function cloudflare_glue_calls(string $function): array {
	$found = array();

	foreach ($GLOBALS['cloudflare_glue']['calls'] ?? array() as $call) {
		if ($call[0] === $function) {
			$found[] = $call[1];
		}
	}

	return $found;
}

/**
 * @param mixed $key
 * @return mixed
 */
function cloudflare_glue_state($key) {
	if (!isset($GLOBALS['cloudflare_glue'])) {
		cloudflare_glue_reset();
	}

	return $GLOBALS['cloudflare_glue'][$key];
}

if (!function_exists('api_plugin_valid_entrypoint')) {
	/* lib/plugins.php:1124, logic copied so backtrace depth matches. */
	/**
	 * @param mixed $plugin
	 * @param mixed $function
	 */
	function api_plugin_valid_entrypoint($plugin, $function): bool {
		$backtrace = debug_backtrace();
		if (cacti_sizeof($backtrace)) {
			if (!preg_match('/(install|upgrade|setup)/i', $backtrace[2]['function'])) {
				cacti_log(sprintf(
					'WARNING: Plugin \'%s\' is attempting to call \'%s\' improperly in function \'%s\'',
					$plugin,
					$function,
					$backtrace[2]['function']
				), false, 'PLUGIN');

				return false;
			}
		}

		return true;
	}
}

if (!function_exists('api_plugin_register_hook')) {
	/* lib/plugins.php:1030 */
	/**
	 * @param mixed $plugin
	 * @param mixed $hook
	 * @param mixed $function
	 * @param mixed $file
	 * @param mixed $enable
	 * @return false|null
	 */
	function api_plugin_register_hook($plugin, $hook, $function, $file, $enable = false) {
		if (!api_plugin_valid_entrypoint($plugin, __FUNCTION__)) {
			return false;
		}

		cloudflare_glue_record(__FUNCTION__, array($plugin, $hook, $function, $file, $enable));

		return null;
	}
}

if (!function_exists('api_plugin_register_realm')) {
	/* lib/plugins.php:1137 */
	/**
	 * @param mixed $plugin
	 * @param mixed $file
	 * @param mixed $display
	 * @param mixed $admin
	 * @return false|null
	 */
	function api_plugin_register_realm($plugin, $file, $display, $admin = true) {
		if (!api_plugin_valid_entrypoint($plugin, __FUNCTION__)) {
			return false;
		}

		cloudflare_glue_record(__FUNCTION__, array($plugin, $file, $display, $admin));

		return null;
	}
}

if (!function_exists('api_plugin_is_enabled')) {
	/* lib/plugins.php:885. The real function caches the first answer in a
	 * static for the life of the process; cloudflare_glue_reset() stands in
	 * for a new process. */
	/**
	 * @param mixed $plugin
	 */
	function api_plugin_is_enabled($plugin): bool {
		cloudflare_glue_record(__FUNCTION__, array($plugin));

		if (!isset($GLOBALS['cloudflare_glue_enabled_cache'][$plugin])) {
			$GLOBALS['cloudflare_glue_enabled_cache'][$plugin] = cloudflare_glue_state('enabled') === true;
		}

		return $GLOBALS['cloudflare_glue_enabled_cache'][$plugin];
	}
}

if (!function_exists('exec_background')) {
	/* lib/poller.php:132 */
	/**
	 * @param mixed $filename
	 * @param mixed $args
	 * @param mixed $redirect_args
	 */
	function exec_background($filename, $args = '', $redirect_args = ''): void {
		cloudflare_glue_record(__FUNCTION__, array($filename, $args, $redirect_args));
	}
}

if (!function_exists('get_current_page')) {
	/* lib/functions.php:4080 */
	/**
	 * @param mixed $basename
	 */
	function get_current_page($basename = true): string {
		return (string) cloudflare_glue_state('page');
	}
}

if (!function_exists('raise_ajax_permission_denied')) {
	/* lib/functions.php:7599 */
	function raise_ajax_permission_denied(): void {
		cloudflare_glue_record(__FUNCTION__, array());
	}
}

if (!function_exists('api_user_realm_auth')) {
	/* lib/plugins.php:35 */
	/**
	 * @param mixed $filename
	 */
	function api_user_realm_auth($filename = ''): bool {
		cloudflare_glue_record(__FUNCTION__, array($filename));

		/* A closure answers per realm file, for code that checks several. */
		$realm = cloudflare_glue_state('realm');

		return $realm instanceof Closure ? $realm((string) $filename) === true : $realm === true;
	}
}

if (!function_exists('cacti_sizeof')) {
	/* lib/functions.php:7577 */
	/**
	 * @param mixed $array
	 */
	function cacti_sizeof($array): int {
		return ($array === false || !is_array($array)) ? 0 : count($array);
	}
}

if (!function_exists('cacti_log')) {
	/* lib/functions.php:1273 */
	/**
	 * @param mixed $string
	 * @param mixed $output
	 * @param mixed $environ
	 * @param mixed $level
	 */
	function cacti_log($string, $output = false, $environ = 'CMDPHP', $level = ''): bool {
		cloudflare_glue_record(__FUNCTION__, array($string, $output, $environ, $level));

		/* Lets a test act at a known point inside a run, such as between accounts. */
		$on_log = cloudflare_glue_state('on_log');
		if (is_callable($on_log)) {
			$on_log((string) $string);
		}

		return true;
	}
}

if (!function_exists('read_config_option')) {
	/*
	 * lib/functions.php:680. 'settings' is the settings table and 'cached'
	 * the per-request copy Cacti keeps in $config['config_options_array'] or
	 * the session (:686-697): a value read once is served from the copy
	 * until $force asks for the table again (:704, :743-748).
	 */
	/**
	 * @param mixed $config_name
	 * @param mixed $force
	 * @return mixed
	 */
	function read_config_option($config_name, $force = false) {
		if (!isset($GLOBALS['cloudflare_glue'])) {
			cloudflare_glue_reset();
		}

		if ($force || !array_key_exists($config_name, $GLOBALS['cloudflare_glue']['cached'])) {
			$settings = cloudflare_glue_state('settings');

			$GLOBALS['cloudflare_glue']['cached'][$config_name] = $settings[$config_name] ?? '';
		}

		return $GLOBALS['cloudflare_glue']['cached'][$config_name];
	}
}

if (!function_exists('db_fetch_cell_prepared')) {
	/* lib/database.php:665 */
	/**
	 * @param mixed $sql
	 * @param mixed $params
	 * @param mixed $col_name
	 * @param mixed $log
	 * @param mixed $db_conn
	 * @return mixed
	 */
	function db_fetch_cell_prepared($sql, $params = array(), $col_name = '', $log = true, $db_conn = false) {
		cloudflare_glue_record(__FUNCTION__, array($sql, $params));

		/* A closure answers per query, for code that reads several cells. */
		$cell = cloudflare_glue_state('cell');

		return $cell instanceof Closure ? $cell((string) $sql, (array) $params) : $cell;
	}
}

if (!function_exists('set_config_option')) {
	/*
	 * lib/functions.php:418 (1.2.25: :419). It writes the table, then
	 * overwrites the whole per-request copy with the scalar value (:475-479),
	 * so every later read goes back to the table.
	 */
	/**
	 * @param mixed $config_name
	 * @param mixed $value
	 * @param mixed $remote
	 * @return void
	 */
	function set_config_option($config_name, $value, $remote = false) {
		cloudflare_glue_record(__FUNCTION__, array($config_name, $value, $remote));

		$GLOBALS['cloudflare_glue']['settings'][$config_name] = $value;
		$GLOBALS['cloudflare_glue']['cached']                 = array();
	}
}

if (!function_exists('import_xml_data')) {
	/*
	 * lib/import.php:25, identical in 1.2.25 and 1.2.31. Records the sha256
	 * of the XML rather than the XML itself. The 'import' state is the
	 * return value, or a closure that builds it and may fill the global
	 * $import_messages the way a parse error does (lib/import.php:38).
	 */
	/**
	 * @param mixed $xml_data
	 * @param mixed $import_as_new
	 * @param mixed $profile_id
	 * @param mixed $remove_orphans
	 * @param mixed $replace_svalues
	 * @param mixed $import_hashes
	 * @param mixed $class
	 * @return mixed
	 */
	function import_xml_data(
		&$xml_data,
		$import_as_new,
		$profile_id,
		$remove_orphans = false,
		$replace_svalues = false,
		$import_hashes = array(),
		$class = ''
	) {
		cloudflare_glue_record(__FUNCTION__, array(hash('sha256', (string) $xml_data), $import_as_new, $profile_id));

		$import = cloudflare_glue_state('import');

		return $import instanceof Closure ? $import() : $import;
	}
}

if (!function_exists('db_execute_prepared')) {
	/* lib/database.php:437 */
	/**
	 * @param mixed $sql
	 * @param mixed $params
	 * @param mixed $log
	 * @param mixed $db_conn
	 * @param mixed $execute_name
	 * @param mixed $default_value
	 * @param mixed $return_func
	 * @param mixed $return_params
	 */
	function db_execute_prepared(
		$sql,
		$params = array(),
		$log = true,
		$db_conn = false,
		$execute_name = 'Exec',
		$default_value = true,
		$return_func = 'no_return_function',
		$return_params = array()
	): bool {
		cloudflare_glue_record(__FUNCTION__, array($sql, $params));

		return true;
	}
}

if (!function_exists('db_connect_real')) {
	/* lib/database.php:43 */
	/**
	 * @param mixed $device
	 * @param mixed $user
	 * @param mixed $pass
	 * @param mixed $db_name
	 * @param mixed $db_type
	 * @param mixed $port
	 * @param mixed $retries
	 * @param mixed $db_ssl
	 * @param mixed $db_ssl_key
	 * @param mixed $db_ssl_cert
	 * @param mixed $db_ssl_ca
	 * @param mixed $persist
	 * @return mixed
	 */
	function db_connect_real(
		$device,
		$user,
		$pass,
		$db_name,
		$db_type = 'mysql',
		$port = '3306',
		$retries = 20,
		$db_ssl = false,
		$db_ssl_key = '',
		$db_ssl_cert = '',
		$db_ssl_ca = '',
		$persist = false
	) {
		cloudflare_glue_record(__FUNCTION__, array($device, $db_name, $port));

		return cloudflare_glue_state('connect');
	}
}

if (!function_exists('isset_request_var')) {
	/* lib/html_utility.php:345 */
	/**
	 * @param mixed $variable
	 */
	function isset_request_var($variable): bool {
		return isset($_REQUEST[$variable]);
	}
}

if (!function_exists('get_request_var')) {
	/* lib/html_utility.php:387 */
	/**
	 * @param mixed $name
	 * @param mixed $default
	 * @return mixed
	 */
	function get_request_var($name, $default = '') {
		return $_REQUEST[$name] ?? $default;
	}
}

if (!function_exists('get_nfilter_request_var')) {
	/* lib/html_utility.php:539 */
	/**
	 * @param mixed $name
	 * @param mixed $default
	 * @return mixed
	 */
	function get_nfilter_request_var($name, $default = '') {
		return $_REQUEST[$name] ?? $default;
	}
}

if (!function_exists('html_escape')) {
	/* lib/html.php:1178 */
	/**
	 * @param mixed $string
	 * @return mixed
	 */
	function html_escape($string) {
		if ($string !== null) {
			$string = str_replace('`', '&#96;', (string) $string);

			return htmlspecialchars($string, ENT_QUOTES | ENT_HTML5, 'UTF-8', false);
		}

		return $string;
	}
}

if (!function_exists('__')) {
	/* include/global_languages.php:570; the last argument may name a text domain. */
	/**
	 * @return string|false
	 */
	function __() {
		$args = func_get_args();
		$num  = func_num_args();

		if ($num < 1) {
			return false;
		}

		if ($num > 1 && $args[$num - 1] === 'cloudflare') {
			array_pop($args);
		}

		$text = (string) array_shift($args);

		return $args === array() ? $text : vsprintf($text, $args);
	}
}

if (!function_exists('__esc')) {
	/* include/global_languages.php:495 */
	function __esc(): string {
		return htmlspecialchars((string) call_user_func_array('__', func_get_args()), ENT_QUOTES);
	}
}

/*
 * Page rendering helpers. They print nothing and record their arguments, so
 * a handler test can assert what reached each one.
 */

if (!defined('MAX_DISPLAY_PAGES')) {
	define('MAX_DISPLAY_PAGES', 5);
}

if (!class_exists('CactiSecureHeaders')) {
	/* lib/headers_secure.php, added after 1.2.25; callers check class_exists(). */
	class CactiSecureHeaders {
		/**
		 * @return string
		 */
		public static function getNonceAttribute() {
			return '';
		}
	}
}

if (!function_exists('raise_message')) {
	/* lib/functions.php:996 */
	/**
	 * @param mixed $message_id
	 * @param mixed $message
	 * @param mixed $message_level
	 */
	function raise_message($message_id, $message = '', $message_level = MESSAGE_LEVEL_NONE): void {
		cloudflare_glue_record(__FUNCTION__, array($message_id, $message, $message_level));
	}
}

if (!function_exists('__n')) {
	/* include/global_languages.php:556 */
	/**
	 * @param mixed $singular
	 * @param mixed $plural
	 * @param mixed $number
	 * @param mixed $domain
	 */
	function __n($singular, $plural, $number, $domain = 'cacti'): string {
		return (string) ($number == 1 ? $singular : $plural);
	}
}

if (!function_exists('top_header')) {
	/* lib/functions.php:4853 */
	function top_header(): void {
		cloudflare_glue_record(__FUNCTION__, array());

		/* Lets a test make the page chrome fail, as a broken theme or template would. */
		$on_top = cloudflare_glue_state('on_top');
		if (is_callable($on_top)) {
			$on_top();
		}
	}
}

if (!function_exists('bottom_footer')) {
	/* lib/functions.php:4826 */
	function bottom_footer(): void {
		cloudflare_glue_record(__FUNCTION__, array());
	}
}

if (!function_exists('get_filter_request_var')) {
	/*
	 * lib/html_utility.php:426, for the filter_var() filters only. Where the
	 * real function calls die_html_input_error() and exits, this throws, so
	 * a test cannot pass input the real page would refuse.
	 */
	/**
	 * @param mixed $name
	 * @param mixed $filter
	 * @param mixed $options
	 * @return mixed
	 */
	function get_filter_request_var($name, $filter = FILTER_VALIDATE_INT, $options = array()) {
		if (!isset($_REQUEST[$name])) {
			if (isset($options['default'])) {
				$_REQUEST[$name] = $options['default'];

				return $options['default'];
			}

			return null;
		}

		$raw = $_REQUEST[$name];

		if (empty($raw)) {
			return $raw;
		}

		if ($raw == 'undefined') {
			$_REQUEST[$name] = $options['default'] ?? '';

			return $_REQUEST[$name];
		}

		$filters = array(
			FILTER_VALIDATE_INT,
			FILTER_DEFAULT,
			FILTER_CALLBACK,
			FILTER_VALIDATE_REGEXP,
			FILTER_VALIDATE_FLOAT,
			FILTER_VALIDATE_BOOLEAN,
		);
		if (!is_int($filter) || !in_array($filter, $filters, true)) {
			throw new LogicException('get_filter_request_var stub does not model filter ' . var_export($filter, true));
		}

		$value = is_array($options) && count($options) ? filter_var($raw, $filter, $options) : filter_var($raw, $filter);

		if ($value === false) {
			throw new RuntimeException('die_html_input_error(' . $name . ')');
		}

		$_REQUEST[$name] = $value;

		return $value;
	}
}

if (!function_exists('set_default_action')) {
	/* lib/html_utility.php:312 */
	/**
	 * @param mixed $default
	 */
	function set_default_action($default = ''): void {
		if (!isset($_REQUEST['action'])) {
			$_REQUEST['action'] = $default;
		}
	}
}

if (!function_exists('validate_store_request_vars')) {
	/*
	 * lib/html_utility.php:628, for the filter_var() filters only. Session
	 * fallback, defaults, 'clear' and the page reset on a changed pageset
	 * filter follow Cacti; where it calls die_html_input_error() and exits,
	 * this throws, so a test cannot pass input the real page would refuse.
	 */
	/**
	 * @param mixed $filters
	 * @param mixed $sess_prefix
	 */
	function validate_store_request_vars($filters, $sess_prefix = ''): void {
		cloudflare_glue_record(__FUNCTION__, array($filters, $sess_prefix));

		$changed  = 0;
		$modelled = array(FILTER_VALIDATE_INT, FILTER_DEFAULT, FILTER_CALLBACK, FILTER_VALIDATE_REGEXP);

		foreach ($filters as $variable => $options) {
			$session_variable = '';

			if ($sess_prefix != '') {
				if (isset($options['session'])) {
					$session_variable = $options['session'];
				} elseif ($variable != 'rows') {
					$session_variable = $sess_prefix . '_' . $variable;
				} else {
					$session_variable = 'sess_default_rows';
				}

				if (isset($_REQUEST['clear'])) {
					unset($_SESSION[$session_variable], $_REQUEST[$variable]);
				} elseif (isset($_REQUEST['reset'])) {
					unset($_SESSION[$session_variable]);
				} elseif (!empty($options['pageset']) && isset($_REQUEST[$variable], $_SESSION[$session_variable])
					&& $_REQUEST[$variable] != $_SESSION[$session_variable]) {
					$changed++;
				}
			}

			if (!isset($_REQUEST[$variable])) {
				if ($session_variable !== '' && isset($_SESSION[$session_variable])) {
					$_REQUEST[$variable] = $_SESSION[$session_variable];
				} else {
					$_REQUEST[$variable] = $options['default'] ?? '';
				}
			} else {
				$raw = $_REQUEST[$variable];

				if ($raw == '0') {
					$value = '0';
				} elseif ($raw == 'undefined') {
					$value = $options['default'] ?? '';
				} elseif (empty($raw)) {
					$value = '';
				} elseif (!in_array($options['filter'], $modelled, true)) {
					throw new LogicException(
						'validate_store_request_vars stub does not model filter ' . var_export($options['filter'], true)
					);
				} elseif (!isset($options['options'])) {
					$value = filter_var($raw, $options['filter']);
				} else {
					$value = filter_var($raw, $options['filter'], $options['options']);
				}

				if ($value === false) {
					throw new RuntimeException('die_html_input_error(' . $variable . ')');
				}

				$_REQUEST[$variable] = $value;
			}

			if ($session_variable !== '') {
				$_SESSION[$session_variable] = $_REQUEST[$variable];
			}
		}

		if ($changed) {
			$_REQUEST['page']                = 1;
			$_REQUEST['changed']             = 1;
			$_SESSION[$sess_prefix . '_page'] = 1;
		}
	}
}

if (!function_exists('sanitize_search_string')) {
	/* lib/functions.php:4474 */
	/**
	 * @param mixed $string
	 * @return mixed
	 */
	function sanitize_search_string($string) {
		static $drop_char_match = array(
			'(', ')', '^', '$', '<', '>', '`', '\'', '"', '|', ',',
			'?', '+', '[', ']', '{', '}', '#', ';', '!', '=', '*',
		);
		static $drop_char_replace = array(
			'', '', ' ', ' ', ' ', ' ', '', '', ' ', ' ', ' ',
			' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ',
		);

		$string = preg_replace('/[\n\r]/is', ' ', $string);
		$string = preg_replace('/\b&[a-z]+;\b/', ' ', $string);
		$string = preg_replace('/\b[a-z0-9]+:\/\/[a-z0-9\.\-]+(\/[a-z0-9\?\.%_\-\+=&\/]+)?/', ' ', $string);

		return str_replace($drop_char_match, $drop_char_replace, $string);
	}
}

if (!function_exists('sanitize_unserialize_selected_items')) {
	/* lib/functions.php:4695 */
	/**
	 * @param mixed $items
	 * @return array<mixed>|false
	 */
	function sanitize_unserialize_selected_items($items) {
		if ($items == '') {
			return false;
		}

		$unstripped = stripslashes($items);

		if (!preg_match('/^a:[0-9]+:{/', $unstripped) || preg_match('/(^|;|{|})O:\+?[0-9]+:"/', $unstripped)) {
			return false;
		}

		$items = unserialize($unstripped, array('allowed_classes' => false));

		if (!is_array($items)) {
			return false;
		}

		foreach ($items as $item) {
			if (is_array($item) || (!is_numeric($item) && $item != '')) {
				return false;
			}
		}

		return $items;
	}
}

if (!function_exists('html_escape_request_var')) {
	/* lib/html.php:1171 */
	/**
	 * @param mixed $string
	 * @return mixed
	 */
	function html_escape_request_var($string) {
		return html_escape(get_request_var($string));
	}
}

if (!function_exists('html_start_box')) {
	/* lib/html.php:58 */
	/**
	 * @param mixed $title
	 * @param mixed $width
	 * @param mixed $div
	 * @param mixed $cell_padding
	 * @param mixed $align
	 * @param mixed $add_text
	 * @param mixed $add_label
	 */
	function html_start_box($title, $width, $div, $cell_padding, $align, $add_text, $add_label = false): void {
		cloudflare_glue_record(__FUNCTION__, array($title, $width, $div, $cell_padding, $align, $add_text, $add_label));
	}
}

if (!function_exists('html_end_box')) {
	/* lib/html.php:189 */
	/**
	 * @param mixed $trailing_br
	 * @param mixed $div
	 */
	function html_end_box($trailing_br = true, $div = false): void {
		cloudflare_glue_record(__FUNCTION__, array($trailing_br, $div));
	}
}

if (!function_exists('html_nav_bar')) {
	/* lib/html.php:582 */
	/**
	 * @param mixed $base_url
	 * @param mixed $max_pages
	 * @param mixed $current_page
	 * @param mixed $rows_per_page
	 * @param mixed $total_rows
	 * @param mixed $colspan
	 * @param mixed $object
	 * @param mixed $page_var
	 * @param mixed $return_to
	 * @param mixed $page_count
	 */
	function html_nav_bar(
		$base_url,
		$max_pages,
		$current_page,
		$rows_per_page,
		$total_rows,
		$colspan = 30,
		$object = '',
		$page_var = 'page',
		$return_to = '',
		$page_count = true
	): string {
		cloudflare_glue_record(__FUNCTION__, array(
			$base_url,
			$max_pages,
			$current_page,
			$rows_per_page,
			$total_rows,
			$colspan,
			$object,
			$page_var,
			$return_to,
			$page_count,
		));

		return '';
	}
}

if (!function_exists('html_header')) {
	/* lib/html.php:1018 */
	/**
	 * @param mixed $header_items
	 * @param mixed $last_item_colspan
	 */
	function html_header($header_items, $last_item_colspan = 1): void {
		cloudflare_glue_record(__FUNCTION__, array($header_items, $last_item_colspan));
	}
}

if (!function_exists('html_header_sort')) {
	/* lib/html.php:665 */
	/**
	 * @param mixed $header_items
	 * @param mixed $sort_column
	 * @param mixed $sort_direction
	 * @param mixed $last_item_colspan
	 * @param mixed $url
	 * @param mixed $return_to
	 */
	function html_header_sort(
		$header_items,
		$sort_column,
		$sort_direction,
		$last_item_colspan = 1,
		$url = '',
		$return_to = ''
	): void {
		cloudflare_glue_record(
			__FUNCTION__,
			array($header_items, $sort_column, $sort_direction, $last_item_colspan, $url, $return_to)
		);
	}
}

if (!function_exists('html_header_sort_checkbox')) {
	/* lib/html.php:845 */
	/**
	 * @param mixed $header_items
	 * @param mixed $sort_column
	 * @param mixed $sort_direction
	 * @param mixed $include_form
	 * @param mixed $form_action
	 * @param mixed $return_to
	 * @param mixed $prefix
	 */
	function html_header_sort_checkbox(
		$header_items,
		$sort_column,
		$sort_direction,
		$include_form = true,
		$form_action = '',
		$return_to = '',
		$prefix = 'chk'
	): void {
		cloudflare_glue_record(__FUNCTION__, array(
			$header_items,
			$sort_column,
			$sort_direction,
			$include_form,
			$form_action,
			$return_to,
			$prefix,
		));
	}
}

if (!function_exists('draw_actions_dropdown')) {
	/* lib/html.php:1648 */
	/**
	 * @param mixed $actions_array
	 * @param mixed $delete_action
	 */
	function draw_actions_dropdown($actions_array, $delete_action = 1): void {
		cloudflare_glue_record(__FUNCTION__, array($actions_array, $delete_action));
	}
}

if (!function_exists('form_alternate_row')) {
	/* lib/html_utility.php:178 */
	/**
	 * @param mixed $row_id
	 * @param mixed $light
	 * @param mixed $disabled
	 */
	function form_alternate_row($row_id = '', $light = false, $disabled = false): void {
		cloudflare_glue_record(__FUNCTION__, array($row_id, $light, $disabled));
	}
}

if (!function_exists('form_selectable_cell')) {
	/* lib/html_utility.php:235 */
	/**
	 * @param mixed $contents
	 * @param mixed $id
	 * @param mixed $width
	 * @param mixed $style_or_class
	 * @param mixed $title
	 */
	function form_selectable_cell($contents, $id, $width = '', $style_or_class = '', $title = ''): void {
		cloudflare_glue_record(__FUNCTION__, array($contents, $id, $width, $style_or_class, $title));
	}
}

if (!function_exists('form_checkbox_cell')) {
	/* lib/html_utility.php:270 */
	/**
	 * @param mixed $title
	 * @param mixed $id
	 * @param mixed $disabled
	 */
	function form_checkbox_cell($title, $id, $disabled = false): void {
		cloudflare_glue_record(__FUNCTION__, array($title, $id, $disabled));
	}
}

if (!function_exists('form_end_row')) {
	/* lib/html_utility.php:277 */
	function form_end_row(): void {
		cloudflare_glue_record(__FUNCTION__, array());
	}
}

if (!function_exists('draw_edit_form')) {
	/* lib/html_form.php:38 */
	/**
	 * @param mixed $array
	 */
	function draw_edit_form($array): void {
		cloudflare_glue_record(__FUNCTION__, array($array));
	}
}

if (!function_exists('form_save_button')) {
	/* lib/html_form.php:1412 */
	/**
	 * @param mixed $cancel_url
	 * @param mixed $force_type
	 * @param mixed $key_field
	 * @param mixed $ajax
	 */
	function form_save_button($cancel_url, $force_type = '', $key_field = 'id', $ajax = true): void {
		cloudflare_glue_record(__FUNCTION__, array($cancel_url, $force_type, $key_field, $ajax));
	}
}

if (!function_exists('form_start')) {
	/* lib/html_form.php:1492 */
	/**
	 * @param mixed $action
	 * @param mixed $id
	 * @param mixed $multipart
	 */
	function form_start($action, $id = '', $multipart = false): void {
		cloudflare_glue_record(__FUNCTION__, array($action, $id, $multipart));
	}
}

if (!function_exists('form_end')) {
	/* lib/html_form.php:1515 */
	/**
	 * @param mixed $ajax
	 */
	function form_end($ajax = true): void {
		cloudflare_glue_record(__FUNCTION__, array($ajax));
	}
}

/*
 * Device creation. The stubs keep a small model of the host and
 * host_snmp_query tables in the glue state, so a test can check the
 * reindex method a device ends up with, not only the calls made.
 */

if (!defined('DATA_QUERY_AUTOINDEX_NONE')) {
	/* include/global_constants.php:75, identical in 1.2.25 and 1.2.31. */
	define('DATA_QUERY_AUTOINDEX_NONE', 0);
	define('DATA_QUERY_AUTOINDEX_BACKWARDS_UPTIME', 1);
}

if (!function_exists('is_error_message')) {
	/* lib/functions.php:893 (1.2.25: :890); logic copied. */
	function is_error_message(): bool {
		if (isset($_SESSION['sess_error_fields']) && cacti_sizeof($_SESSION['sess_error_fields'])) {
			return true;
		}

		return false;
	}
}

if (!function_exists('run_data_query')) {
	/* lib/data_query.php:25, same signature in 1.2.25 and 1.2.31. */
	/**
	 * @param mixed $host_id
	 * @param mixed $snmp_query_id
	 * @param mixed $automation
	 * @param mixed $force
	 */
	function run_data_query($host_id, $snmp_query_id, $automation = false, $force = false): bool {
		cloudflare_glue_record(__FUNCTION__, array($host_id, $snmp_query_id, $automation, $force));

		return (bool) cloudflare_glue_state('reindex');
	}
}

if (!function_exists('api_device_dq_add')) {
	/*
	 * lib/api_device.php:552, identical in 1.2.25 and 1.2.31: REPLACE INTO
	 * host_snmp_query with the given method, then run_data_query() (:578).
	 */
	/**
	 * @param mixed $device_id
	 * @param mixed $data_query_id
	 * @param mixed $reindex_method
	 */
	function api_device_dq_add($device_id, $data_query_id, $reindex_method): void {
		cloudflare_glue_record(__FUNCTION__, array($device_id, $data_query_id, $reindex_method));

		$GLOBALS['cloudflare_glue']['host_queries'][(int) $device_id][(int) $data_query_id] = (int) $reindex_method;

		run_data_query($device_id, $data_query_id);
	}
}

if (!function_exists('api_device_save')) {
	/*
	 * lib/api_device.php:939, same 29 parameters in 1.2.25 and 1.2.31.
	 * Modelled: nothing is saved while is_error_message() holds (:1035);
	 * 'invalid' stands for a field form_input_validate() refuses, which
	 * sets sess_error_fields; for SNMP version 0 the host's queries get
	 * reindex method None (:1073-1077); and only after that a new device
	 * template adds its queries with the global default and runs them
	 * (:1130 calls api_device_update_host_template(), whose REPLACE and
	 * run_data_query() are at :1288 and :1308 in 1.2.31, :1269 and :1289
	 * in 1.2.25).
	 */
	/**
	 * @param mixed $id
	 * @param mixed $device_template_id
	 * @param mixed $description
	 * @param mixed $hostname
	 * @param mixed $snmp_community
	 * @param mixed $snmp_version
	 * @param mixed $snmp_username
	 * @param mixed $snmp_password
	 * @param mixed $snmp_port
	 * @param mixed $snmp_timeout
	 * @param mixed $disabled
	 * @param mixed $availability_method
	 * @param mixed $ping_method
	 * @param mixed $ping_port
	 * @param mixed $ping_timeout
	 * @param mixed $ping_retries
	 * @param mixed $notes
	 * @param mixed $snmp_auth_protocol
	 * @param mixed $snmp_priv_passphrase
	 * @param mixed $snmp_priv_protocol
	 * @param mixed $snmp_context
	 * @param mixed $snmp_engine_id
	 * @param mixed $max_oids
	 * @param mixed $device_threads
	 * @param mixed $poller_id
	 * @param mixed $site_id
	 * @param mixed $external_id
	 * @param mixed $location
	 * @param mixed $bulk_walk_size
	 * @return mixed
	 */
	function api_device_save(
		$id,
		$device_template_id,
		$description,
		$hostname,
		$snmp_community,
		$snmp_version,
		$snmp_username,
		$snmp_password,
		$snmp_port,
		$snmp_timeout,
		$disabled,
		$availability_method,
		$ping_method,
		$ping_port,
		$ping_timeout,
		$ping_retries,
		$notes,
		$snmp_auth_protocol,
		$snmp_priv_passphrase,
		$snmp_priv_protocol,
		$snmp_context,
		$snmp_engine_id,
		$max_oids = 5,
		$device_threads = 1,
		$poller_id = 1,
		$site_id = 1,
		$external_id = '',
		$location = '',
		$bulk_walk_size = -1
	) {
		cloudflare_glue_record(__FUNCTION__, func_get_args());

		if (cloudflare_glue_state('invalid') === true) {
			$_SESSION['sess_error_fields']['hostname'] = 'hostname';
		}

		if (is_error_message()) {
			return 0;
		}

		$device_id = (int) cloudflare_glue_state('next_host');

		$GLOBALS['cloudflare_glue']['next_host']++;
		$GLOBALS['cloudflare_glue']['hosts'][$device_id]        = (string) $hostname;
		$GLOBALS['cloudflare_glue']['host_queries'][$device_id] = array();

		raise_message(1);

		$templates = cloudflare_glue_state('template_queries');

		foreach ($templates[(int) $device_template_id] ?? array() as $data_query_id) {
			$GLOBALS['cloudflare_glue']['host_queries'][$device_id][(int) $data_query_id] = (int) read_config_option('reindex_method');

			run_data_query($device_id, $data_query_id);
		}

		return $device_id;
	}
}

if (!function_exists('api_device_remove')) {
	/*
	 * lib/api_device.php:45, identical in 1.2.25 and 1.2.31. It deletes the
	 * host row (poller 1) and its host_snmp_query, cache, poller and graph
	 * rows, and returns nothing. 'remove_fails' leaves the host in place,
	 * as a failed DELETE would; real Cacti reports that only in its log.
	 */
	/**
	 * @param mixed $device_id
	 */
	function api_device_remove($device_id): void {
		cloudflare_glue_record(__FUNCTION__, array($device_id));

		if (cloudflare_glue_state('remove_fails') === true) {
			return;
		}

		unset($GLOBALS['cloudflare_glue']['hosts'][(int) $device_id], $GLOBALS['cloudflare_glue']['host_queries'][(int) $device_id]);
	}
}

/*
 * Graph creation. graph_local and data_local are modelled in the glue
 * state, so a test can see which graphs exist and which data source each
 * one uses, and answer the glue's graph_local lookups from them.
 */

if (!function_exists('get_best_data_query_index_type')) {
	/*
	 * lib/data_query.php:2318 (1.2.25: :2285), identical in both: the
	 * host's sort_field, else the XML's first index_order field.
	 */
	/**
	 * @param mixed $host_id
	 * @param mixed $data_query_id
	 * @return mixed
	 */
	function get_best_data_query_index_type($host_id, $data_query_id) {
		cloudflare_glue_record(__FUNCTION__, array($host_id, $data_query_id));

		return cloudflare_glue_state('index_type');
	}
}

if (!function_exists('create_complete_graph_from_template')) {
	/*
	 * lib/template.php:1480 in 1.2.25 and 1.2.31, $suggested_vals by
	 * reference. Returns false or array('local_graph_id' => id,
	 * 'local_data_id' => array(data_template_id => id)) (:1845). Modelled:
	 * data_source_exists() (:2194, called at :1594; 1.2.25 :1596) finds the
	 * data source of the same host, data template, query and index, and
	 * :1613 reuses it, which is how a tunnel's graphs share one RRD.
	 */
	/**
	 * @param mixed $graph_template_id
	 * @param mixed $host_id
	 * @param mixed $snmp_query_array
	 * @param mixed $suggested_vals
	 * @return mixed
	 */
	function create_complete_graph_from_template($graph_template_id, $host_id, $snmp_query_array, &$suggested_vals) {
		cloudflare_glue_record(__FUNCTION__, array($graph_template_id, $host_id, $snmp_query_array, $suggested_vals));

		if (empty($graph_template_id)) {
			return false;
		}

		$data_template_id = (int) cloudflare_glue_state('data_template_id');
		$local_data_id    = 0;
		$key              = array(
			$data_template_id,
			(int) $host_id,
			(int) $snmp_query_array['snmp_query_id'],
			(string) $snmp_query_array['snmp_index'],
		);

		foreach (cloudflare_glue_state('data_local') as $id => $row) {
			if ($row === $key) {
				$local_data_id = (int) $id;

				break;
			}
		}

		if ($local_data_id === 0) {
			$local_data_id = (int) cloudflare_glue_state('next_data');

			$GLOBALS['cloudflare_glue']['next_data']++;
			$GLOBALS['cloudflare_glue']['data_local'][$local_data_id] = $key;
		}

		$local_graph_id = (int) cloudflare_glue_state('next_graph');

		$GLOBALS['cloudflare_glue']['next_graph']++;
		$GLOBALS['cloudflare_glue']['graph_local'][$local_graph_id] = array(
			'graph_template_id'   => (int) $graph_template_id,
			'host_id'             => (int) $host_id,
			'snmp_query_id'       => (int) $snmp_query_array['snmp_query_id'],
			'snmp_query_graph_id' => (int) $snmp_query_array['snmp_query_graph_id'],
			'snmp_index'          => (string) $snmp_query_array['snmp_index'],
			'local_data_id'       => $local_data_id,
		);

		return array('local_graph_id' => $local_graph_id, 'local_data_id' => array($data_template_id => $local_data_id));
	}
}

if (!function_exists('push_out_host')) {
	/* lib/utility.php:834 (1.2.25: :687), same signature in both. */
	/**
	 * @param mixed $host_id
	 * @param mixed $local_data_id
	 * @param mixed $data_template_id
	 */
	function push_out_host($host_id, $local_data_id = 0, $data_template_id = 0): void {
		cloudflare_glue_record(__FUNCTION__, array($host_id, $local_data_id, $data_template_id));
	}
}

if (!function_exists('api_graph_remove')) {
	/* lib/api_graph.php:109 in 1.2.25 and 1.2.31; recorded so a test can prove it is never called. */
	/**
	 * @param mixed $local_graph_id
	 */
	function api_graph_remove($local_graph_id): void {
		cloudflare_glue_record(__FUNCTION__, array($local_graph_id));

		unset($GLOBALS['cloudflare_glue']['graph_local'][(int) $local_graph_id]);
	}
}

if (!function_exists('api_data_source_remove')) {
	/* lib/api_data_source.php:57 in 1.2.25 and 1.2.31; recorded so a test can prove it is never called. */
	/**
	 * @param mixed $local_data_id
	 */
	function api_data_source_remove($local_data_id): void {
		cloudflare_glue_record(__FUNCTION__, array($local_data_id));

		unset($GLOBALS['cloudflare_glue']['data_local'][(int) $local_data_id]);
	}
}
