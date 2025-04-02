<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
	->exclude('vendor')
	->in(__DIR__)
	->name('*.php')
;

$config = new PhpCsFixer\Config();
return $config->setRules([
	// Base standards
	'@PSR2' => true,
	'@PSR12' => true,

	// Array syntax and formatting
	'array_syntax' => ['syntax' => 'short'], // Matches Generic.Arrays.DisallowLongArraySyntax
	'array_indentation' => true, // Matches Generic.Arrays.ArrayIndent
	'binary_operator_spaces' => [
		'default' => 'single_space',
		'operators' => ['=' => 'align_single_space_minimal']
	],
	'trailing_comma_in_multiline' => true,
	'trim_array_spaces' => true,
	'whitespace_after_comma_in_array' => true,
	'no_whitespace_before_comma_in_array' => true,

	// Class and function related
	'blank_line_after_namespace' => true,
	'class_attributes_separation' => ['elements' => ['const' => 'one', 'method' => 'one', 'property' => 'one']],
	'no_blank_lines_after_class_opening' => true,
	'visibility_required' => ['elements' => ['property', 'method']], // PSR2 requirement

	// Braces and control structures
	'braces' => [
		'allow_single_line_anonymous_class_with_empty_body' => true, 
		'allow_single_line_closure' => true, 
		'position_after_functions_and_oop_constructs' => 'next', 
		'position_after_control_structures' => 'same', 
		'position_after_anonymous_constructs' => 'same'
	],
	'control_structure_continuation_position' => ['position' => 'same_line'],
	'elseif' => true,
	'no_alternative_syntax' => true,

	// Spacing and indentation
	'concat_space' => ['spacing' => 'none'], // Matches Squiz.Strings.ConcatenationSpacing
	'function_typehint_space' => true,
	'indentation_type' => true,
	'line_ending' => true,
	'method_argument_space' => [
		'on_multiline' => 'ensure_fully_multiline',
		'keep_multiple_spaces_after_comma' => false,
	],
	'method_chaining_indentation' => true,
	'no_spaces_after_function_name' => true,
	'no_spaces_inside_parenthesis' => true,
	'no_trailing_whitespace' => true,
	'no_trailing_whitespace_in_comment' => true,
	'statement_indentation' => true,

	// Comments and PHPDocs
	'multiline_comment_opening_closing' => true,
	'phpdoc_add_missing_param_annotation' => true,
	'phpdoc_align' => ['align' => 'left'],
	'phpdoc_indent' => true,
	'phpdoc_line_span' => ['const' => 'single', 'property' => 'single', 'method' => 'multi'],
	'phpdoc_no_access' => true,
	'phpdoc_no_empty_return' => true,
	'phpdoc_no_package' => false, // PHPCS doesn't exclude package tag
	'phpdoc_order' => true,
	'phpdoc_scalar' => true,
	'phpdoc_separation' => true,
	'phpdoc_single_line_var_spacing' => true,
	'phpdoc_trim' => true,
	'phpdoc_types' => true,

	// Coding style
	'constant_case' => ['case' => 'lower'],
	'lowercase_cast' => true,
	'lowercase_keywords' => true,
	'normalize_index_brace' => true,
	'standardize_not_equals' => true,
	'full_opening_tag' => true,
	'no_closing_tag' => true,
	'no_empty_statement' => true,
	'ordered_imports' => ['sort_algorithm' => 'alpha'],
	'no_unused_imports' => true,
	'single_blank_line_at_eof' => true,
	'single_import_per_statement' => true,
	'single_line_after_imports' => true,
	
	// PHP Features
	'explicit_indirect_variable' => true,
	'fully_qualified_strict_types' => true,
	'no_unneeded_control_parentheses' => true,
	'return_type_declaration' => ['space_before' => 'none'],
	
	// Explicitly align with PHPCS ForbiddenFunctions rule
	'non_printable_character' => true,
	'void_return' => true,
	
	// Compatibility with Squiz.PHP.DisallowInlineIf
	'no_short_bool_cast' => true,
	
	// Function spacing to match Squiz.WhiteSpace.FunctionSpacing
	'blank_line_before_statement' => [
		'statements' => ['break', 'case', 'continue', 'declare', 'default', 'return', 'throw', 'try']
	],
	
	// Match PSR2.Files.EndFileNewline
	'single_blank_line_at_eof' => true,
	
	// Align with Generic.ControlStructures.DisallowYodaConditions
	'yoda_style' => ['equal' => false, 'identical' => false, 'less_and_greater' => false],
	
	// Match line length limits from PHPCS
	'heredoc_indentation' => true,
	
	// PHPUnit specific rules
	'php_unit_construct' => true,
	'php_unit_method_casing' => ['case' => 'camel_case'],
])
	->setFinder($finder)
	->setIndent("    ")
	->setLineEnding("\n");
