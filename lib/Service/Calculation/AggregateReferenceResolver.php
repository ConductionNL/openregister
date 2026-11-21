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

use OCA\OpenRegister\Search\Service\Aggregation\AggregationQuery;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Resolves `x-openregister-aggregate-refs` into an `@aggregate` payload map.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/calc-engine-aggregate-reference/tasks.md#task-1
 */
class AggregateReferenceResolver implements AggregateReferenceResolverInterface {

	use PayloadTokenReading;
	/**
	 * Wire the aggregation runner used to fold referenced objects and the logger.
	 *
	 * @param AggregationRunner $aggregationRunner Ad-hoc aggregation runner (RBAC + tenant scoped).
	 * @param LoggerInterface $logger PSR logger for unresolved-aggregate warnings.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/calc-engine-aggregate-reference/tasks.md#task-1
	 */
	public function __construct(
		private readonly AggregationRunner $aggregationRunner,
		private readonly LoggerInterface $logger,
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
	 * The referrer's register (slug/uuid/id) is the default aggregation context
	 * when a spec omits its own `register`.
	 *
	 * @param ReferringObject      $referrer   The saving object: payload WITH `@self` injected, and its register.
	 * @param array<string, mixed> $aggregates The `x-openregister-aggregate-refs` map.
	 *
	 * @return array<string, mixed> Map of aggregate-reference name to resolved value (or null).
	 *
	 * @spec openspec/changes/calc-engine-aggregate-reference/tasks.md#task-1
	 */
	public function resolveAll(ReferringObject $referrer, array $aggregates): array {
		return $this->resolveOutcome(referrer: $referrer, aggregates: $aggregates)['values'];
	}//end resolveAll()

	/**
	 * As resolveAll(), and also which references could not be resolved and why.
	 *
	 * A failed reference resolves to null in `values` (the save path's rule);
	 * a caller that must not read that null as a real value (the rematerialise
	 * command: live pass O9 counted 205 rows "unchanged") reads `failed`.
	 *
	 * @param ReferringObject      $referrer   The saving object: payload and register.
	 * @param array<string, mixed> $aggregates The declared aggregate-references.
	 *
	 * @return array{values: array<string, mixed>, failed: array<string, string>} Values and failures by name.
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	public function resolveOutcome(ReferringObject $referrer, array $aggregates): array {
		$values = [];
		$failed = [];
		foreach ($this->usableDeclarations(declarations: $aggregates) as $name => $spec) {
			try {
				$values[$name] = $this->resolveOne(referrer: $referrer, spec: $spec);
			} catch (Throwable $e) {
				$values[$name] = null;
				$failed[(string)$name] = $this->failureOf(spec: $spec, error: $e);
			}
		}

		return ['values' => $values, 'failed' => $failed];
	}//end resolveOutcome()

	/**
	 * Log a failed aggregate-reference and name why it failed.
	 *
	 * @param array<string, mixed> $spec The aggregate-reference declaration.
	 * @param Throwable $error The failure.
	 *
	 * @return string The failure message.
	 */
	private function failureOf(array $spec, Throwable $error): string {
		$this->logger->warning(
			sprintf('Aggregate-reference resolution failed for schema "%s": %s', (string)($spec['schema'] ?? ''), $error->getMessage())
		);

		return $error->getMessage();
	}//end failureOf()

	/**
	 * Resolve a single aggregate-reference spec to a scalar / grouped map, or null.
	 *
	 * @param ReferringObject      $referrer The saving object: payload and register (default context).
	 * @param array<string, mixed> $spec     Single aggregate-reference declaration.
	 *
	 * @return mixed The scalar value, a `{stringKey: value}` grouped map, or null.
	 *
	 * @throws RuntimeException When the aggregation cannot run (resolveOutcome() records it).
	 *
	 * @spec openspec/changes/calc-engine-aggregate-reference/tasks.md#task-1
	 */
	private function resolveOne(ReferringObject $referrer, array $spec): mixed {
		$target = $this->targetOf(spec: $spec, registerRef: $referrer->register);
		if ($target === null) {
			return null;
		}

		['schema' => $schema, 'register' => $register] = $target;
		try {
			$query = $this->queryFor(payload: $referrer->payload, spec: $spec);
			// As the system, whoever saves: a materialised aggregate is the
			// schema author's derived value, the same rule references follow
			// (calculations-resolve-references-regardless-of-saver). Under occ
			// there is no session at all, and every aggregate resolved to null
			// (live pass O9: 410 "no permission to aggregate" warnings).
			$result = $this->aggregationRunner->runAdhocByRef(registerRef: $register, schemaRef: $schema, query: $query, bypassRbac: true);
		} catch (Throwable $e) {
			throw new RuntimeException($e->getMessage(), 0, $e);
		}

		return $this->envelopeToValue(result: $result);
	}//end resolveOne()

	/**
	 * The schema, metric and register an aggregate-reference folds, or null
	 * when one of them is missing.
	 *
	 * The aggregate-reference may override the register; it defaults to the
	 * saving object's register so the fold stays within the same dataset.
	 *
	 * @param array<string, mixed> $spec Single aggregate-reference declaration.
	 * @param string|int|null $registerRef Saving object's register ref (default context).
	 *
	 * @return array{schema: string, metric: string, register: string}|null The target, or null.
	 */
	private function targetOf(array $spec, mixed $registerRef): ?array {
		$schema = (string)($spec['schema'] ?? '');
		$metric = (string)($spec['metric'] ?? '');
		if ($schema === '' || $metric === '') {
			return null;
		}

		$register = (string)($spec['register'] ?? $registerRef ?? '');
		if ($register === '') {
			return null;
		}

		return ['schema' => $schema, 'metric' => $metric, 'register' => $register];
	}//end targetOf()

	/**
	 * Build the aggregation query of an aggregate-reference whose target targetOf() found.
	 *
	 * @param array<string, mixed> $payload Object data with `@self` injected.
	 * @param array<string, mixed> $spec Single aggregate-reference declaration; its metric is set.
	 *
	 * @return AggregationQuery The query.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) AggregationQuery::create() is the engine's
	 *   fail-fast static factory (mirrors AggregationRunner's own usage); no instance
	 *   alternative exists.
	 */
	private function queryFor(array $payload, array $spec): AggregationQuery {
		$metric = (string)($spec['metric'] ?? '');
		$field = ($spec['field'] ?? null);
		if (is_string($field) === false) {
			$field = null;
		}

		$groupBy = ($spec['groupBy'] ?? null);
		if (is_array($groupBy) === false) {
			$groupBy = null;
		}

		$filters = $this->filtersFor(payload: $payload, spec: $spec);
		return AggregationQuery::create(metric: $metric, field: $field, filter: $filters, groupBy: $groupBy);
	}//end queryFor()

	/**
	 * Parameterise the filter map by the saving object: literals pass
	 * through, `@self.<field>` tokens resolve against the payload. This
	 * keeps the pure evaluator out of the resolution path.
	 *
	 * @param array<string, mixed> $payload Object data with `@self` injected.
	 * @param array<string, mixed> $spec Single aggregate-reference declaration.
	 *
	 * @return array<string, mixed> The resolved filters.
	 */
	private function filtersFor(array $payload, array $spec): array {
		$rawFilters = ($spec['filters'] ?? []);
		if (is_array($rawFilters) === false) {
			return [];
		}

		return $this->resolveTokens(payload: $payload, criteria: $rawFilters);
	}//end filtersFor()

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
	private function envelopeToValue(array $result): mixed {
		if (array_key_exists('groups', $result) === true && is_array($result['groups']) === true) {
			return $this->groupMap(groups: $result['groups']);
		}

		if (array_key_exists('value', $result) === true) {
			return $result['value'];
		}

		return null;
	}//end envelopeToValue()

	/**
	 * The `{stringKey: value}` map of a grouped envelope.
	 *
	 * @param array<int, mixed> $groups The envelope's groups.
	 *
	 * @return array<string, mixed> The map.
	 */
	private function groupMap(array $groups): array {
		$map = [];
		foreach ($groups as $group) {
			if (is_array($group) === false || array_key_exists('key', $group) === false) {
				continue;
			}

			$map[(string)$group['key']] = ($group['value'] ?? null);
		}

		return $map;
	}//end groupMap()
}//end class
