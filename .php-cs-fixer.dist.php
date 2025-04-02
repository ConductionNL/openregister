<?php

declare(strict_types=1);

require_once './vendor-bin/cs-fixer/vendor/autoload.php';

use Nextcloud\CodingStandard\Config;

$finder = PhpCsFixer\Finder::create()
	->exclude('vendor')
	->in(__DIR__)
	->name('*.php')
;

return (new PhpCsFixer\Config())
	->setRules([
		'@PSR1' => true,
		'@PSR2' => true,
		'array_syntax' => ['syntax' => 'short'],
		'blank_line_after_opening_tag' => true,
		'blank_line_before_statement' => [
			'statements' => ['return', 'throw', 'try', 'if', 'switch', 'case', 'default'],
		],
		'braces' => [
			'allow_single_line_anonymous_class_with_empty_body' => true,
			'allow_single_line_closure' => true,
			'position_after_control_structures' => 'same',
			'position_after_functions_and_oop_constructs' => 'next',
			'position_after_anonymous_constructs' => 'next',
		],
		'cast_spaces' => ['space' => 'single'],
		'class_attributes_separation' => [
			'elements' => [
				'const' => 'one',
				'method' => 'one',
				'property' => 'one',
				'trait_import' => 'none',
			],
		],
		'concat_space' => ['spacing' => 'none'],
		'constant_case' => ['case' => 'upper'],
		'declare_equal_normalize' => ['space' => 'none'],
		'elseif' => true,
		'encoding' => true,
		'full_opening_tag' => true,
		'function_declaration' => ['closure_function_spacing' => 'one'],
		'function_typehint_space' => true,
		'indentation_type' => true,
		'line_ending' => true,
		'linebreak_after_opening_tag' => true,
		'lowercase_cast' => true,
		'lowercase_keywords' => true,
		'method_argument_space' => [
			'on_multiline' => 'ensure_fully_multiline',
			'keep_multiple_spaces_after_comma' => false,
		],
		'multiline_whitespace_before_semicolons' => ['strategy' => 'no_multi_line'],
		'native_function_casing' => true,
		'no_blank_lines_after_class_opening' => true,
		'no_blank_lines_after_phpdoc' => true,
		'no_closing_tag' => true,
		'no_empty_phpdoc' => true,
		'no_empty_statement' => true,
		'no_extra_blank_lines' => ['tokens' => [
			'case',
			'continue',
			'curly_brace_block',
			'default',
			'extra',
			'parenthesis_brace_block',
			'square_brace_block',
			'switch',
			'throw',
			'use',
			'use_trait',
		]],
		'no_leading_import_slash' => true,
		'no_leading_namespace_whitespace' => true,
		'no_mixed_echo_print' => ['use' => 'echo'],
		'no_multiline_whitespace_around_double_arrow' => true,
		'no_short_bool_cast' => true,
		'no_singleline_whitespace_before_semicolons' => true,
		'no_spaces_after_function_name' => true,
		'no_spaces_around_offset' => ['positions' => ['inside']],
		'no_spaces_inside_parenthesis' => true,
		'no_trailing_comma_in_list_call' => true,
		'no_trailing_comma_in_singleline_array' => true,
		'no_trailing_whitespace' => true,
		'no_trailing_whitespace_in_comment' => true,
		'no_unneeded_control_parentheses' => ['statements' => [
			'break',
			'clone',
			'continue',
			'echo_print',
			'return',
			'switch_case',
			'yield',
		]],
		'no_unreachable_default_argument_value' => true,
		'no_unused_imports' => true,
		'no_useless_return' => true,
		'no_whitespace_before_comma_in_array' => true,
		'no_whitespace_in_blank_line' => true,
		'normalize_index_brace' => true,
		'not_operator_with_successor_space' => true,
		'object_operator_without_whitespace' => true,
		'ordered_imports' => ['sort_algorithm' => 'alpha'],
		'phpdoc_add_missing_param_annotation' => true,
		'phpdoc_indent' => true,
		'phpdoc_no_access' => true,
		'phpdoc_no_package' => false,
		'phpdoc_no_useless_inheritdoc' => true,
		'phpdoc_order' => true,
		'phpdoc_scalar' => true,
		'phpdoc_separation' => true,
		'phpdoc_single_line_var_spacing' => true,
		'phpdoc_summary' => true,
		'phpdoc_to_comment' => true,
		'phpdoc_trim' => true,
		'phpdoc_types' => true,
		'phpdoc_var_without_name' => true,
		'return_type_declaration' => ['space_before' => 'none'],
		'self_accessor' => true,
		'short_scalar_cast' => true,
		'simplified_null_return' => false,
		'single_blank_line_at_eof' => true,
		'single_blank_line_before_namespace' => true,
		'single_class_element_per_statement' => true,
		'single_import_per_statement' => true,
		'single_line_after_imports' => true,
		'single_quote' => ['strings_containing_single_quote_chars' => false],
		'space_after_semicolon' => true,
		'standardize_not_equals' => true,
		'switch_case_semicolon_to_colon' => true,
		'switch_case_space' => true,
		'ternary_operator_spaces' => true,
		'trailing_comma_in_multiline' => ['elements' => ['arrays']],
		'trim_array_spaces' => true,
		'unary_operator_spaces' => true,
		'visibility_required' => ['elements' => ['method', 'property']],
		'whitespace_after_comma_in_array' => true,
	])
	->setLineEnding("\n")
	->setIndent("    ")
	->setFinder($finder)
;
