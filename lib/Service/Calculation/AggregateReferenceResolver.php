<?php

/**
 * OpenRegister AggregateReferenceResolver
 *
 * Pre-resolves declared aggregate-references (`x-openregister-aggregate-refs`)
 * for the calculation engine. Called by CalculationOnSaveListener and
 * RematerialiseCalculationsCommand BEFORE any calculation is evaluated, it
 * folds MANY objects of a target schema into a value (scalar or grouped) via
 * AggregationRunner::runAdhoc() and injects each result under
 * `@aggregate.<name>` so JSON-AST calculations can read it via
 * `{ "prop": "@aggregate.<name>" }` (or `.<groupKey>` for grouped) — exactly
 * mirroring `@self` and `@ref` (Extension 1, ReferenceResolver).
 *
 * Resolution uses AggregationRunner::runAdhocByRef(), which gates on the
 * schema's `list` permission and applies the multi-tenancy predicate under the
 * saving user's session, so an aggregate never folds over rows the saver
 * cannot read and never leaks cross-tenant data. Any failure injects null and
 * is logged; the save is never failed.
 *
 * The CalculationEvaluator stays pure: all I/O lives here.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calculation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Calculation;

use DateTimeImmutable;
use OCA\OpenRegister\Service\Aggregation\AggregationQuery;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves `x-openregister-aggregate-refs` into an `@aggregate` payload map.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/calc-engine-aggregate-reference/tasks.md#task-1
 */
class AggregateReferenceResolver
{
    /**
     * Wire the aggregation runner used to fold referenced objects and the logger.
     *
     * @param AggregationRunner $aggregationRunner Ad-hoc aggregation runner (RBAC + tenant scoped).
     * @param LoggerInterface   $logger            PSR logger for unresolved-aggregate warnings.
     *
     * @return void
     *
     * @spec openspec/changes/calc-engine-aggregate-reference/tasks.md#task-1
     */
    public function __construct(
        private readonly AggregationRunner $aggregationRunner,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Build the `@aggregate` map for an object's declared aggregate-references.
     *
     * Each declared aggregate-reference resolves to a scalar (ungrouped) or a
     * `{stringKey: value}` map (grouped), or null on failure. The result is
     * intended to be injected into the evaluation payload as
     * `$payload['@aggregate']` so calculations can read `@aggregate.<name>`
     * (or `@aggregate.<name>.<groupKey>`).
     *
     * @param array<string, mixed> $payload     Object data WITH `@self` already injected.
     * @param array<string, mixed> $aggregates  The `x-openregister-aggregate-refs` map.
     * @param string|int|null      $registerRef Saving object's register (slug/uuid/id) — the default
     *                                          aggregation context when a spec omits its own `register`.
     *
     * @return array<string, mixed> Map of aggregate-reference name to resolved value (or null).
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     *
     * @spec openspec/changes/calc-engine-aggregate-reference/tasks.md#task-1
     */
    public function resolveAll(array $payload, array $aggregates, mixed $registerRef=null): array
    {
        $result = [];
        foreach ($aggregates as $name => $spec) {
            if ((string) $name === '' || is_array($spec) === false) {
                continue;
            }

            $result[$name] = $this->resolveOne(payload: $payload, spec: $spec, registerRef: $registerRef);
        }

        return $result;
    }//end resolveAll()

    /**
     * Resolve a single aggregate-reference spec to a scalar / grouped map, or null.
     *
     * @param array<string, mixed> $payload     Object data with `@self` injected.
     * @param array<string, mixed> $spec        Single aggregate-reference declaration.
     * @param string|int|null      $registerRef Saving object's register ref (default context).
     *
     * @return mixed The scalar value, a `{stringKey: value}` grouped map, or null.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.StaticAccess) AggregationQuery::create() is the engine's
     *   fail-fast static factory (mirrors AggregationRunner's own usage); no instance
     *   alternative exists.
     *
     * @spec openspec/changes/calc-engine-aggregate-reference/tasks.md#task-1
     */
    private function resolveOne(array $payload, array $spec, mixed $registerRef): mixed
    {
        $schema = (string) ($spec['schema'] ?? '');
        $metric = (string) ($spec['metric'] ?? '');
        if ($schema === '' || $metric === '') {
            return null;
        }

        // Allow the aggregate-reference to override the register; default to
        // the saving object's register so the fold stays within the same dataset.
        $aggRegister = (string) ($spec['register'] ?? $registerRef ?? '');
        if ($aggRegister === '') {
            return null;
        }

        // Parameterise the filter map by the saving object: literals pass
        // through, `@self.<field>` tokens resolve against the payload. This
        // keeps the pure evaluator out of the resolution path.
        $filters    = [];
        $rawFilters = ($spec['filters'] ?? []);
        if (is_array($rawFilters) === true) {
            foreach ($rawFilters as $target => $criterion) {
                $filters[(string) $target] = $this->resolveToken(payload: $payload, token: $criterion);
            }
        }

        $field = ($spec['field'] ?? null);
        if (is_string($field) === false) {
            $field = null;
        }

        $groupBy = ($spec['groupBy'] ?? null);
        if (is_array($groupBy) === false) {
            $groupBy = null;
        }

        try {
            $query  = AggregationQuery::create(
                metric: $metric,
                field: $field,
                filter: $filters,
                groupBy: $groupBy
            );
            $result = $this->aggregationRunner->runAdhocByRef(
                registerRef: $aggRegister,
                schemaRef: $schema,
                query: $query
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                sprintf('Aggregate-reference resolution failed for schema "%s": %s', $schema, $e->getMessage())
            );
            return null;
        }//end try

        return $this->envelopeToValue(result: $result);
    }//end resolveOne()

    /**
     * Map an AggregationRunner result envelope to the injected value.
     *
     * A scalar envelope (`{value: …}`) injects the scalar directly. A grouped
     * envelope (`{groups: [{key, value}, …]}`) injects a `{stringKey: value}`
     * map so a calculation reads `@aggregate.<name>.<groupKey>`.
     *
     * @param array<string, mixed> $result The runner result envelope.
     *
     * @return mixed The scalar value, a grouped map, or null.
     *
     * @spec openspec/changes/calc-engine-aggregate-reference/tasks.md#task-1
     */
    private function envelopeToValue(array $result): mixed
    {
        if (array_key_exists('groups', $result) === true && is_array($result['groups']) === true) {
            $map = [];
            foreach ($result['groups'] as $group) {
                if (is_array($group) === false || array_key_exists('key', $group) === false) {
                    continue;
                }

                $key = $group['key'];
                $map[(string) $key] = ($group['value'] ?? null);
            }

            return $map;
        }

        if (array_key_exists('value', $result) === true) {
            return $result['value'];
        }

        return null;
    }//end envelopeToValue()

    /**
     * Resolve a criterion token to a concrete value.
     *
     * Supported tokens (kept deliberately small to avoid coupling to the pure
     * CalculationEvaluator), mirroring ReferenceResolver:
     * - A literal scalar (passed through unchanged).
     * - A `@self.<field>` / `@ref`-prefixed dotted-path string, resolved against the payload.
     * - A single-key AST node `{ "year": <token> }`.
     *
     * @param array<string, mixed> $payload Object data with `@self` injected.
     * @param mixed                $token   The criterion token.
     *
     * @return mixed The resolved value.
     *
     * @spec openspec/changes/calc-engine-aggregate-reference/tasks.md#task-1
     */
    private function resolveToken(array $payload, mixed $token): mixed
    {
        if (is_string($token) === true) {
            if (str_starts_with($token, '@self.') === true || str_starts_with($token, '@ref.') === true) {
                return $this->readPath(payload: $payload, path: $token);
            }

            return $token;
        }

        if (is_array($token) === true && count($token) === 1) {
            $op = (string) array_key_first($token);
            if ($op === 'year') {
                return $this->yearToken(payload: $payload, arg: $token[$op]);
            }
        }

        return $token;
    }//end resolveToken()

    /**
     * Resolve a `{ "year": <token> }` criterion to a four-digit year integer.
     *
     * @param array<string, mixed> $payload Object data with `@self` injected.
     * @param mixed                $arg     The inner token (typically a `@self.<date>` reference).
     *
     * @return int|null The year, or null when the inner token is not a parseable date.
     *
     * @spec openspec/changes/calc-engine-aggregate-reference/tasks.md#task-1
     */
    private function yearToken(array $payload, mixed $arg): ?int
    {
        $raw = $this->resolveToken(payload: $payload, token: $arg);
        if (is_string($raw) === false || $raw === '') {
            return null;
        }

        try {
            return (int) (new DateTimeImmutable($raw))->format('Y');
        } catch (Throwable) {
            return null;
        }
    }//end yearToken()

    /**
     * Read a dotted path (e.g. `@self.resourceId`) from the payload.
     *
     * `@self.<field>` may address either the injected `@self` system metadata
     * (id/created/…) OR one of the object's OWN data fields; the listener
     * injects user data at the top level, so when `@self.<field>` is not a
     * system field fall back to the top-level field. This mirrors
     * ReferenceResolver so aggregate filters parameterise by the saving object
     * exactly as the schema author expects.
     *
     * @param array<string, mixed> $payload Object data.
     * @param string               $path    Dotted path.
     *
     * @return mixed The resolved value, or null when the path is missing.
     *
     * @spec openspec/changes/calc-engine-aggregate-reference/tasks.md#task-1
     */
    private function readPath(array $payload, string $path): mixed
    {
        $parts = explode('.', $path);

        if ($parts[0] === '@self' && count($parts) === 2) {
            $field = $parts[1];
            $self  = ($payload['@self'] ?? []);
            if (is_array($self) === true && array_key_exists($field, $self) === true) {
                return $self[$field];
            }

            return ($payload[$field] ?? null);
        }

        $current = $payload;
        foreach ($parts as $part) {
            if (is_array($current) === false || array_key_exists($part, $current) === false) {
                return null;
            }

            $current = $current[$part];
        }

        return $current;
    }//end readPath()
}//end class
