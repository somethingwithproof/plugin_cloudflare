<?php
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

$finder = PhpCsFixer\Finder::create()
	->in(array(__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/features'));

return (new PhpCsFixer\Config())
	->setIndent("\t")
	->setLineEnding("\n")
	->setRiskyAllowed(false)
	->setRules(array(
		'@PSR12'                        => true,
		'blank_line_after_opening_tag'  => false,
		'modifier_keywords'              => array('elements' => array('method', 'property')),
		'indentation_type'              => true,
		'single_quote'                  => true,
		'no_spaces_inside_parenthesis'  => true,
		'constant_case'                 => array('case' => 'lower'),
		'braces_position'               => array(
			'functions_opening_brace'  => 'same_line',
			'classes_opening_brace'    => 'same_line',
			'control_structures_opening_brace' => 'same_line',
		),
	))
	->setFinder($finder);
