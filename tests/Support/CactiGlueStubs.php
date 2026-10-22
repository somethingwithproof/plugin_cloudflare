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
		'calls'    => array(),
		'settings' => array(),
		'cell'     => false,
		'realm'    => true,
		'connect'  => false,
		'enabled'  => true,
		'page'     => 'index.php',
	);
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
				cacti_log(sprintf('WARNING: Plugin \'%s\' is attempting to call \'%s\' improperly in function \'%s\'', $plugin, $function, $backtrace[2]['function']), false, 'PLUGIN');

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
	/* lib/plugins.php:885 */
	/**
	 * @param mixed $plugin
	 */
	function api_plugin_is_enabled($plugin): bool {
		cloudflare_glue_record(__FUNCTION__, array($plugin));

		return cloudflare_glue_state('enabled') === true;
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
	}
}

if (!function_exists('api_user_realm_auth')) {
	/* lib/plugins.php:35 */
	/**
	 * @param mixed $filename
	 */
	function api_user_realm_auth($filename = ''): bool {
		cloudflare_glue_record(__FUNCTION__, array($filename));

		return cloudflare_glue_state('realm') === true;
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

		return true;
	}
}

if (!function_exists('read_config_option')) {
	/* lib/functions.php:680 */
	/**
	 * @param mixed $config_name
	 * @param mixed $force
	 * @return mixed
	 */
	function read_config_option($config_name, $force = false) {
		$settings = cloudflare_glue_state('settings');

		return $settings[$config_name] ?? '';
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

		return cloudflare_glue_state('cell');
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
	function db_execute_prepared($sql, $params = array(), $log = true, $db_conn = false, $execute_name = 'Exec', $default_value = true, $return_func = 'no_return_function', $return_params = array()): bool {
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
	}
}

if (!function_exists('bottom_footer')) {
	/* lib/functions.php:4826 */
	function bottom_footer(): void {
		cloudflare_glue_record(__FUNCTION__, array());
	}
}

if (!function_exists('get_filter_request_var')) {
	/* lib/html_utility.php:426 */
	/**
	 * @param mixed $name
	 * @param mixed $filter
	 * @param mixed $options
	 * @return mixed
	 */
	function get_filter_request_var($name, $filter = FILTER_VALIDATE_INT, $options = array()) {
		return $_REQUEST[$name] ?? '';
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
	/* lib/html_utility.php:628 */
	/**
	 * @param mixed $filters
	 * @param mixed $sess_prefix
	 */
	function validate_store_request_vars($filters, $sess_prefix = ''): void {
		cloudflare_glue_record(__FUNCTION__, array($filters, $sess_prefix));
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
	function html_nav_bar($base_url, $max_pages, $current_page, $rows_per_page, $total_rows, $colspan = 30, $object = '', $page_var = 'page', $return_to = '', $page_count = true): string {
		cloudflare_glue_record(__FUNCTION__, array($base_url, $max_pages, $current_page, $rows_per_page, $total_rows, $colspan, $object, $page_var, $return_to, $page_count));

		return '';
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
	function html_header_sort_checkbox($header_items, $sort_column, $sort_direction, $include_form = true, $form_action = '', $return_to = '', $prefix = 'chk'): void {
		cloudflare_glue_record(__FUNCTION__, array($header_items, $sort_column, $sort_direction, $include_form, $form_action, $return_to, $prefix));
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
