<?php

declare(strict_types=1);

require_once './vendor-bin/cs-fixer/vendor/autoload.php';

use Nextcloud\CodingStandard\Config;

$finder = PhpCsFixer\Finder::create()
	->exclude('vendor')
	->in(__DIR__)
	->name('*.php')
;

$config = new PhpCsFixer\Config();
return $config->setRules([
	'@PSR2' => true,
	'array_syntax' => ['syntax' => 'short'],
	'binary_operator_spaces' => true,
	'blank_line_after_namespace' => true,
	'class_attributes_separation' => ['elements' => ['const' => 'one', 'method' => 'one', 'property' => 'one']],
	'constant_case' => ['case' => 'upper'],
	'concat_space' => ['spacing' => 'none'],
	'declare_equal_normalize' => ['space' => 'none'],
	'elseif' => true,
	'encoding' => true,
	'full_opening_tag' => true,
	'function_declaration' => ['closure_function_spacing' => 'one'],
	'indentation_type' => true,
	'line_ending' => true,
	'lowercase_cast' => true,
	'lowercase_keywords' => true,
	'method_argument_space' => [
		'on_multiline' => 'ensure_fully_multiline',
		'keep_multiple_spaces_after_comma' => false,
	],
	'no_closing_tag' => true,
	'no_empty_statement' => true,
	'no_extra_blank_lines' => ['tokens' => ['extra']],
	'no_leading_namespace_whitespace' => true,
	'no_multiline_whitespace_around_double_arrow' => true,
	'no_spaces_after_function_name' => true,
	'no_spaces_around_offset' => ['positions' => ['inside']],
	'no_spaces_inside_parenthesis' => true,
	'no_trailing_whitespace' => true,
	'no_trailing_whitespace_in_comment' => true,
	'no_unused_imports' => true,
	'ordered_imports' => ['sort_algorithm' => 'alpha'],
	'single_blank_line_at_eof' => true,
	'single_blank_line_before_namespace' => true,
	'single_import_per_statement' => true,
	'single_line_after_imports' => true,
	'switch_case_semicolon_to_colon' => true,
	'switch_case_space' => true,
	'visibility_required' => ['elements' => ['property', 'method']],
])
	->setFinder($finder)
	->setIndent("    ")
	->setLineEnding("\n");
