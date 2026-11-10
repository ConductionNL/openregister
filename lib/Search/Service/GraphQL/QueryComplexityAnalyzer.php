<?php

/**
 * Query Complexity Analyzer
 *
 * Analyzes GraphQL query complexity and enforces limits to prevent resource abuse.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\GraphQL
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/graphql-api/spec.md#requirement-query-complexity-analysis-must-prevent-resource-abuse
 */

namespace OCA\OpenRegister\Search\Service\GraphQL;

use GraphQL\Error\Error;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\AST\FieldNode;
use GraphQL\Language\AST\InlineFragmentNode;
use GraphQL\Language\AST\IntValueNode;
use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Language\AST\SelectionSetNode;
use GraphQL\Language\AST\ValueNode;
use GraphQL\Language\AST\VariableNode;
use OCP\IAppConfig;

/**
 * Analyzes GraphQL query complexity and enforces limits.
 *
 * Performs static analysis on the AST before execution to prevent
 * resource abuse through deeply nested or excessively broad queries.
 *
 * @psalm-suppress UnusedClass
 */
class QueryComplexityAnalyzer {

	private const DEFAULT_MAX_DEPTH = 10;
	private const DEFAULT_MAX_COST = 10000;
	private const FIELD_COST = 1;
	private const RESOLVER_COST = 10;

	/**
	 * Per-schema cost overrides.
	 *
	 * @var array<string, int>
	 */
	private array $schemaCosts = [];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Nextcloud app configuration
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Set per-schema cost overrides.
	 *
	 * @param array<string, int> $costs Map of schema slug to cost
	 *
	 * @return void
	 *
	 * @spec openspec/specs/graphql-api/spec.md#requirement-query-complexity-analysis-must-prevent-resource-abuse
	 */
	public function setSchemaCosts(array $costs): void {
		$this->schemaCosts = $costs;

	}//end setSchemaCosts()

	/**
	 * Analyze a document for complexity.
	 *
	 * @param DocumentNode $document The parsed document
	 * @param array<string, mixed>|null $variables Query variables
	 *
	 * @return array{depth: int, cost: int, maxDepth: int, maxCost: int} Analysis result
	 *
	 * @throws Error If query exceeds complexity limits
	 *
	 * @spec openspec/specs/graphql-api/spec.md#requirement-query-complexity-analysis-must-prevent-resource-abuse
	 */
	public function analyze(DocumentNode $document, ?array $variables = null): array {
		$limits = [
			'maxDepth' => $this->limit(key: 'graphql_max_depth', default: self::DEFAULT_MAX_DEPTH),
			'maxCost' => $this->limit(key: 'graphql_max_cost', default: self::DEFAULT_MAX_COST),
		];
		$totals = $this->analyzeDocument(document: $document, variables: $variables);
		$this->assertWithinLimits(totals: $totals, limits: $limits);

		return ($totals + $limits);

	}//end analyze()

	/**
	 * Read one complexity limit from the app config.
	 *
	 * @param string $key     The config key.
	 * @param int    $default The default limit.
	 *
	 * @return int
	 */
	private function limit(string $key, int $default): int {
		return (int)$this->appConfig->getValueString('openregister', $key, (string)$default);
	}//end limit()

	/**
	 * Depth and cost over every operation of a document; fragment definitions are not counted.
	 *
	 * @param DocumentNode              $document  The parsed document
	 * @param array<string, mixed>|null $variables Query variables
	 *
	 * @return array{depth: int, cost: int}
	 */
	private function analyzeDocument(DocumentNode $document, ?array $variables): array {
		$depth = 0;
		$cost = 0;
		$context = new SelectionContext(depth: 0, variables: $variables);
		foreach ($document->definitions as $definition) {
			if ($definition instanceof OperationDefinitionNode === true) {
				$result = $this->analyzeSelectionSet(selectionSet: $definition->selectionSet, context: $context);
				$depth = max($depth, $result['depth']);
				$cost += $result['cost'];
			}
		}

		return ['depth' => $depth, 'cost' => $cost];
	}//end analyzeDocument()

	/**
	 * Refuse a query deeper or costlier than the limits; depth is checked first.
	 *
	 * @param array{depth: int, cost: int}       $totals The query's depth and cost.
	 * @param array{maxDepth: int, maxCost: int} $limits The limits.
	 *
	 * @return void
	 *
	 * @throws Error If query exceeds complexity limits
	 */
	private function assertWithinLimits(array $totals, array $limits): void {
		if ($totals['depth'] > $limits['maxDepth']) {
			throw $this->tooComplex(
				message: "Query depth {$totals['depth']} exceeds maximum allowed depth {$limits['maxDepth']}",
				extensions: ['maxDepth' => $limits['maxDepth'], 'actualDepth' => $totals['depth']]
			);
		}

		if ($totals['cost'] > $limits['maxCost']) {
			throw $this->tooComplex(
				message: "Query cost {$totals['cost']} exceeds maximum allowed cost {$limits['maxCost']}",
				extensions: ['estimatedCost' => $totals['cost'], 'maxCost' => $limits['maxCost']]
			);
		}
	}//end assertWithinLimits()

	/**
	 * The error a too complex query is refused with.
	 *
	 * @param string               $message    The message.
	 * @param array<string, mixed> $extensions Extensions after the `QUERY_TOO_COMPLEX` code.
	 *
	 * @return Error
	 */
	private function tooComplex(string $message, array $extensions): Error {
		return new Error($message, null, null, [], null, null, (['code' => 'QUERY_TOO_COMPLEX'] + $extensions));
	}//end tooComplex()

	/**
	 * Recursively analyze a selection set.
	 *
	 * @param SelectionSetNode $selectionSet The selection set
	 * @param SelectionContext $context      Its nesting depth and the query variables
	 *
	 * @return array{depth: int, cost: int}
	 *
	 * @spec openspec/specs/graphql-api/spec.md#requirement-query-complexity-analysis-must-prevent-resource-abuse
	 */
	private function analyzeSelectionSet(SelectionSetNode $selectionSet, SelectionContext $context): array {
		$maxDepth = $context->depth;
		$totalCost = 0;
		foreach ($selectionSet->selections as $selection) {
			$result = $this->analyzeSelection(selection: $selection, context: $context);
			$maxDepth = max($maxDepth, $result['depth']);
			foreach ($result['costs'] as $cost) {
				$totalCost += $cost;
			}
		}

		return ['depth' => $maxDepth, 'cost' => $totalCost];
	}//end analyzeSelectionSet()

	/**
	 * Depth and cost terms of one selection: a field, an inline fragment, or nothing (a fragment spread).
	 *
	 * The cost terms are added in order by the caller.
	 *
	 * @param mixed            $selection The selection node
	 * @param SelectionContext $context   Its nesting depth and the query variables
	 *
	 * @return array{depth: int, costs: array<int, int|float>}
	 */
	private function analyzeSelection(mixed $selection, SelectionContext $context): array {
		if ($selection instanceof FieldNode) {
			return $this->analyzeField(field: $selection, context: $context);
		}

		if ($selection instanceof InlineFragmentNode) {
			// InlineFragmentNode::$selectionSet is non-nullable.
			$result = $this->analyzeSelectionSet(selectionSet: $selection->selectionSet, context: $context);
			return ['depth' => $result['depth'], 'costs' => [$result['cost']]];
		}

		return ['depth' => $context->depth, 'costs' => []];
	}//end analyzeSelection()

	/**
	 * Depth and cost terms of one field: introspection fields are free, a leaf costs
	 * the field cost, and a field with a selection set adds its resolver cost and its
	 * children's cost times its list multiplier.
	 *
	 * @param FieldNode        $field   The field node
	 * @param SelectionContext $context Its nesting depth and the query variables
	 *
	 * @return array{depth: int, costs: array<int, int|float>}
	 */
	private function analyzeField(FieldNode $field, SelectionContext $context): array {
		$fieldName = $field->name->value;
		if (str_starts_with($fieldName, '__') === true) {
			return ['depth' => $context->depth, 'costs' => []];
		}

		if ($field->selectionSet === null) {
			return ['depth' => $context->depth, 'costs' => [self::FIELD_COST]];
		}

		$multiplier = $this->getListMultiplier(field: $field, variables: $context->variables);
		$resolverCost = $this->getResolverCost(fieldName: $fieldName);
		$child = $this->analyzeSelectionSet(selectionSet: $field->selectionSet, context: $context->deeper());

		return ['depth' => $child['depth'], 'costs' => [self::FIELD_COST, $resolverCost, ($child['cost'] * $multiplier)]];
	}//end analyzeField()

	/**
	 * Get the list multiplier for a field (from the `first` argument).
	 *
	 * @param FieldNode $field The field node
	 * @param array<string, mixed>|null $variables Query variables
	 *
	 * @return int The multiplier
	 *
	 * @spec openspec/specs/graphql-api/spec.md#requirement-query-complexity-analysis-must-prevent-resource-abuse
	 */
	private function getListMultiplier(FieldNode $field, ?array $variables): int {
		foreach ($field->arguments as $arg) {
			if ($arg->name->value !== 'first') {
				continue;
			}

			$multiplier = $this->argumentMultiplier(value: $arg->value, variables: $variables);
			if ($multiplier !== null) {
				return $multiplier;
			}
		}

		return 1;
	}//end getListMultiplier()

	/**
	 * The multiplier one `first` argument gives: its int literal, or the numeric variable it names.
	 *
	 * Variable references (e.g. `$limit` in `first: $limit`) are resolved against the query variables.
	 *
	 * @param ValueNode                 $value     The argument value
	 * @param array<string, mixed>|null $variables Query variables
	 *
	 * @return int|null The multiplier, at least 1, or null when this argument gives none
	 */
	private function argumentMultiplier(ValueNode $value, ?array $variables): ?int {
		if ($value instanceof IntValueNode) {
			return max(1, (int)$value->value);
		}

		if ($value instanceof VariableNode === false || $variables === null) {
			return null;
		}

		$variable = ($variables[$value->name->value] ?? null);
		if (is_numeric(value: $variable) === true) {
			return max(1, (int)$variable);
		}

		return null;
	}//end argumentMultiplier()

	/**
	 * Get the resolver cost for a field name.
	 *
	 * @param string $fieldName The field name
	 *
	 * @return int The cost
	 *
	 * @spec openspec/specs/graphql-api/spec.md#requirement-query-complexity-analysis-must-prevent-resource-abuse
	 */
	private function getResolverCost(string $fieldName): int {
		if (isset($this->schemaCosts[$fieldName]) === true) {
			return $this->schemaCosts[$fieldName];
		}

		return self::RESOLVER_COST;
	}//end getResolverCost()
}//end class
