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
 * The plugin glue (top-level entry points and includes/) follows Cacti's own
 * style, so it is checked with Cacti's .php-cs-fixer.php from the develop
 * branch. src/, tests/ and features/ keep .php-cs-fixer.dist.php.
 *
 * Differences from Cacti's file:
 * - array() and list() stay long: the plugin targets Cacti 1.2, whose code
 *   uses array().
 * - return types keep a space before the colon, as in `function x() : bool`.
 * - risky rules are left out (comment_to_phpdoc, dir_constant,
 *   logical_operators, string_line_ending, strict_param), so this config
 *   never changes behaviour; braces is left out because it is deprecated and
 *   braces_position already sets brace placement.
 */
$finder = PhpCsFixer\Finder::create()
	->in(array(__DIR__, __DIR__ . '/includes'))
	->depth('== 0')
	->name('*.php');

return (new PhpCsFixer\Config())
	->setParallelConfig(PhpCsFixer\Runner\Parallel\ParallelConfigFactory::detect())
	->setRiskyAllowed(false)
	->setCacheFile(__DIR__ . '/.php-cs-fixer.cacti.cache')
	->setIndent("\t")
	->setLineEnding("\n")
	->setRules(array(
		'header_comment'                              => false,
		'phpdoc_align'                                => true,
		'list_syntax'                                 => array('syntax' => 'long'),
		'array_syntax'                                => array('syntax' => 'long'),
		'return_type_declaration'                     => array('space_before' => 'one'),
		'trim_array_spaces'                           => false,
		'no_whitespace_before_comma_in_array'         => true,
		'whitespace_after_comma_in_array'             => true,
		'no_multiline_whitespace_around_double_arrow' => true,
		'no_whitespace_in_blank_line'                 => true,
		'no_trailing_whitespace'                      => true,
		'normalize_index_brace'                       => true,
		'no_mixed_echo_print'                         => array('use' => 'print'),
		'no_spaces_after_function_name'               => true,
		'braces_position'                             => array(
			'anonymous_classes_opening_brace'   => 'same_line',
			'anonymous_functions_opening_brace' => 'same_line',
			'classes_opening_brace'             => 'same_line',
			'functions_opening_brace'           => 'same_line',
		),
		'single_blank_line_at_eof'                    => true,
		'method_chaining_indentation'                 => true,
		'indentation_type'                            => true,
		'constant_case'                               => true,
		'lowercase_keywords'                          => true,
		'line_ending'                                 => true,
		'magic_constant_casing'                       => true,
		'native_function_casing'                      => true,
		'elseif'                                      => true,
		'include'                                     => false,
		'no_alternative_syntax'                       => true,
		'no_superfluous_elseif'                       => true,
		'no_trailing_comma_in_singleline'             => true,
		'no_unneeded_braces'                          => true,
		'no_useless_else'                             => false,
		'yoda_style'                                  => array(
			'equal'                => false,
			'identical'            => false,
			'less_and_greater'     => null,
			'always_move_variable' => false,
		),
		'declare_equal_normalize'                     => array('space' => 'single'),
		'single_space_around_construct'               => array(
			'constructs_followed_by_a_single_space' => array(
				'abstract', 'as', 'attribute', 'break', 'case', 'catch', 'class', 'clone', 'const',
				'const_import', 'continue', 'do', 'echo', 'else', 'elseif', 'extends', 'final', 'finally',
				'for', 'foreach', 'function', 'function_import', 'global', 'goto', 'if', 'implements',
				'instanceof', 'insteadof', 'interface', 'match', 'named_argument', 'new', 'open_tag_with_echo',
				'php_open', 'print', 'private', 'protected', 'public', 'return', 'static', 'throw', 'trait',
				'try', 'use', 'use_lambda', 'use_trait', 'var', 'while', 'yield', 'yield_from',
			),
		),
		'concat_space'                                => array('spacing' => 'one'),
		'switch_case_semicolon_to_colon'              => true,
		'switch_case_space'                           => true,
		'switch_continue_to_break'                    => true,
		'function_declaration'                        => array('closure_function_spacing' => 'one'),
		'spaces_inside_parentheses'                   => true,
		'binary_operator_spaces'                      => array(
			'operators' => array(
				'+='  => 'align_single_space',
				'===' => 'align_single_space_minimal',
				'='   => 'align_single_space',
				'|'   => 'single_space',
				'=>'  => 'align',
				'!='  => 'align',
			),
		),
		'not_operator_with_space'                     => false,
		'no_spaces_around_offset'                     => array('positions' => array('outside', 'inside')),
		'standardize_not_equals'                      => true,
		'ternary_operator_spaces'                     => true,
		'full_opening_tag'                            => false,
		'linebreak_after_opening_tag'                 => false,
		'phpdoc_add_missing_param_annotation'         => true,
		'no_extra_blank_lines'                        => array(
			'tokens' => array(
				'break', 'case', 'continue', 'curly_brace_block', 'default', 'extra',
				'parenthesis_brace_block', 'return', 'square_brace_block', 'switch', 'throw', 'use',
			),
		),
		'no_empty_statement'                          => true,
		'multiline_whitespace_before_semicolons'      => true,
		'no_singleline_whitespace_before_semicolons'  => true,
		'semicolon_after_instruction'                 => false,
		'space_after_semicolon'                       => array('remove_in_empty_for_expressions' => true),
		'blank_line_before_statement'                 => array(
			'statements' => array(
				'continue', 'break', 'declare', 'do', 'for', 'foreach', 'goto', 'if', 'return',
				'switch', 'throw', 'try', 'while', 'yield', 'yield_from',
			),
		),
		'explicit_string_variable'                    => false,
		'single_quote'                                => true,
		'align_multiline_comment'                     => array('comment_type' => 'phpdocs_like'),
		'single_line_comment_spacing'                 => true,
		'single_line_comment_style'                   => true,
		'multiline_comment_opening_closing'           => true,
	))
	->setFinder($finder);
