/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2026 The Cacti Group                                      |
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
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

(function($) {
	'use strict';

	function applyFilter() {
		loadPageNoHeader('cloudflare_accounts.php?header=false' +
			'&filter=' + encodeURIComponent($('#filter').val()) +
			'&rows=' + encodeURIComponent($('#rows').val()));
	}

	function clearFilter() {
		loadPageNoHeader('cloudflare_accounts.php?header=false&clear=1');
	}

	/* The server ignores the token for the file source; hiding it keeps the
	 * form honest about which field is used. */
	function showTokenSource() {
		var source = $('#token_source').val();

		$('#row_token').toggle(source === 'vault');
		$('#row_token_file').toggle(source === 'file');
	}

	$(function() {
		$('#form_cloudflare_accounts').on('submit', function(event) {
			event.preventDefault();
			applyFilter();
		});

		$('#refresh').on('click', applyFilter);
		$('#rows').on('change', applyFilter);
		$('#clear').on('click', clearFilter);

		if ($('#token_source').length) {
			$('#token_source').on('change', showTokenSource);
			showTokenSource();
		}
	});
})(jQuery);
