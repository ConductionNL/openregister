<?php

/**
 * Query Complexity Analyzer
 *
 * Analyzes GraphQL query complexity and enforces limits to prevent resource abuse.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\GraphQL
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-39
 */

namespace OCA\OpenRegister\Service\GraphQL;

use GraphQL\Error\Error;
use GraphQL\Language\AST\FieldNode;
use GraphQL\Language\AST\FragmentSpreadNode;
use GraphQL\Language\AST\InlineFragmentNode;
use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Language\AST\SelectionSetNode;
use GraphQL\Language\AST\DocumentNode;
use OCP\IAppConfig;

/**
 * Analyzes GraphQL query complexity and enforces limits.
 *
 * Performs static analysis on the AST before execution to prevent
 * resource abuse through deeply nested or excessively broad queries.
 *
 * @psalm-suppress UnusedClass
 */
class QueryComplexityAnalyzer
{

    private const DEFAULT_MAX_DEPTH = 10;
    private const DEFAULT_MAX_COST  = 10000;
    private const FIELD_COST        = 1;
    private const RESOLVER_COST     = 10;

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
     */
    public function setSchemaCosts(array $costs): void
    {
        $this->schemaCosts = $costs;

    }//end setSchemaCosts()

    /**
     * Analyze a document for complexity.
     *
     * @param DocumentNode              $document  The parsed document
     * @param array<string, mixed>|null $variables Query variables
     *
     * @return array{depth: int, cost: int, maxDepth: int, maxCost: int} Analysis result
     *
     * @throws Error If query exceeds complexity limits
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-39
     */
    public function analyze(DocumentNode $document, ?array $variables=null): array
    {
        $maxDepth = (int) $this->appConfig->getValueString(
            'openregister',
            'graphql_max_depth',
            (string) self::DEFAULT_MAX_DEPTH
        );
        $maxCost  = (int) $this->appConfig->getValueString(
            'openregister',
            'graphql_max_cost',
            (string) self::DEFAULT_MAX_COST
        );

        $depth = 0;
        $cost  = 0;

        foreach ($document->definitions as $definition) {
            if ($definition instanceof OperationDefinitionNode === false) {
                continue;
            }

            $result = $this->analyzeSelectionSet(
                selectionSet: $definition->selectionSet,
                currentDepth: 0,
                variables: $variables
            );
            $depth  = max($depth, $result['depth']);
            $cost  += $result['cost'];
        }

        if ($depth > $maxDepth) {
            throw new Error(
                "Query depth $depth exceeds maximum allowed depth $maxDepth",
                null,
                null,
                [],
                null,
                null,
                [
                    'code'        => 'QUERY_TOO_COMPLEX',
                    'maxDepth'    => $maxDepth,
                    'actualDepth' => $depth,
                ]
            );
        }

        if ($cost > $maxCost) {
            throw new Error(
                "Query cost $cost exceeds maximum allowed cost $maxCost",
                null,
                null,
                [],
                null,
                null,
                [
                    'code'          => 'QUERY_TOO_COMPLEX',
                    'estimatedCost' => $cost,
                    'maxCost'       => $maxCost,
                ]
            );
        }

        return [
            'depth'    => $depth,
            'cost'     => $cost,
            'maxDepth' => $maxDepth,
            'maxCost'  => $maxCost,
        ];

    }//end analyze()

    /**
     * Recursively analyze a selection set.
     *
     * @param SelectionSetNode          $selectionSet The selection set
     * @param int                       $currentDepth Current nesting depth
     * @param array<string, mixed>|null $variables    Query variables
     *
     * @return array{depth: int, cost: int}
     */
    private function analyzeSelectionSet(
        SelectionSetNode $selectionSet,
        int $currentDepth,
        ?array $variables
    ): array {
        $maxDepth  = $currentDepth;
        $totalCost = 0;

        foreach ($selectionSet->selections as $selection) {
            if ($selection instanceof FieldNode) {
                $fieldName = $selection->name->value;

                // Skip introspection fields.
                if (str_starts_with($fieldName, '__') === true) {
                    continue;
                }

                $totalCost += self::FIELD_COST;

                if ($selection->selectionSet !== null) {
                    $multiplier   = $this->getListMultiplier(field: $selection, variables: $variables);
                    $resolverCost = $this->getResolverCost(fieldName: $fieldName);
                    $totalCost   += $resolverCost;

                    $childResult = $this->analyzeSelectionSet(
                        selectionSet: $selection->selectionSet,
                        currentDepth: ($currentDepth + 1),
                        variables: $variables
                    );
                    $maxDepth    = max($maxDepth, $childResult['depth']);
                    $totalCost  += ($childResult['cost'] * $multiplier);
                }
            } else if ($selection instanceof InlineFragmentNode) {
                if ($selection->selectionSet !== null) {
                    $result     = $this->analyzeSelectionSet(
                        selectionSet: $selection->selectionSet,
                        currentDepth: $currentDepth,
                        variables: $variables
                    );
                    $maxDepth   = max($maxDepth, $result['depth']);
                    $totalCost += $result['cost'];
                }
            }//end if
        }//end foreach

        return [
            'depth' => $maxDepth,
            'cost'  => $totalCost,
        ];

    }//end analyzeSelectionSet()

    /**
     * Get the list multiplier for a field (from the `first` argument).
     *
     * @param FieldNode                 $field     The field node
     * @param array<string, mixed>|null $variables Query variables
     *
     * @return int The multiplier
     */
    private function getListMultiplier(FieldNode $field, ?array $variables): int
    {
        foreach ($field->arguments as $arg) {
            if ($arg->name->value !== 'first') {
                continue;
            }

            $valueNode = $arg->value;
            if ($valueNode instanceof \GraphQL\Language\AST\IntValueNode) {
                return max(1, (int) $valueNode->value);
            }

            // Resolve variable references (e.g., $limit in `first: $limit`).
            if ($valueNode instanceof \GraphQL\Language\AST\VariableNode
                && $variables !== null
            ) {
                $varName = $valueNode->name->value;
                if (isset($variables[$varName]) === true
                    && is_numeric(value: $variables[$varName]) === true
                ) {
                    return max(1, (int) $variables[$varName]);
                }
            }
        }//end foreach

        return 1;

    }//end getListMultiplier()

    /**
     * Get the resolver cost for a field name.
     *
     * @param string $fieldName The field name
     *
     * @return int The cost
     */
    private function getResolverCost(string $fieldName): int
    {
        if (isset($this->schemaCosts[$fieldName]) === true) {
            return $this->schemaCosts[$fieldName];
        }

        return self::RESOLVER_COST;

    }//end getResolverCost()
}//end class
