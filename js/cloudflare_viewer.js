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

(function($) {
	'use strict';

	function applyFilter() {
		loadPageNoHeader('cloudflare.php?action=tunnels&header=false' +
			'&account_id=' + encodeURIComponent($('#account_id').val()) +
			'&lifecycle=' + encodeURIComponent($('#lifecycle').val()) +
			'&cf_status=' + encodeURIComponent($('#cf_status').val()) +
			'&filter=' + encodeURIComponent($('#filter').val()) +
			'&rows=' + encodeURIComponent($('#rows').val()));
	}

	function clearFilter() {
		loadPageNoHeader('cloudflare.php?action=tunnels&header=false&clear=1');
	}

	$(function() {
		$('#form_cloudflare_tunnels').on('submit', function(event) {
			event.preventDefault();
			applyFilter();
		});

		$('#refresh').on('click', applyFilter);
		$('#account_id, #lifecycle, #cf_status, #rows').on('change', applyFilter);
		$('#clear').on('click', clearFilter);
	});
})(jQuery);
