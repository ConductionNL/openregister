<?php

/**
 * OpenRegister NamedAggregationRun
 *
 * The state of one AggregationRunner::run() call: the resolved register and
 * schema, the caller's inputs, the declared spec and what the run derives from
 * them (the merged filter, the cache key, the group fields).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Aggregation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Aggregation;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * One named aggregation run and the spec accessors it reads.
 *
 * `metric` and `select`, and `filter` and `where`, are aliases: the first
 * name wins when both are declared.
 */
final class NamedAggregationRun {

	/**
	 * The schema reference as the caller passed it, for error messages.
	 *
	 * @var string
	 */
	public string $schemaRef = '';

	/**
	 * Internal-system mode: skip the read gate and the row predicate.
	 *
	 * @var boolean
	 */
	public bool $bypassRbac = false;

	/**
	 * Caller-supplied narrowing constraints.
	 *
	 * @var array<string, mixed>
	 */
	public array $extraFilter = [];

	/**
	 * The acting user, null for an anonymous session.
	 *
	 * @var string|null
	 */
	public ?string $userId = null;

	/**
	 * The declared aggregation spec.
	 *
	 * @var array<string, mixed>
	 */
	public array $spec = [];

	/**
	 * The declared filter with placeholders resolved and the narrowing merged in.
	 *
	 * @var array<string, mixed>
	 */
	public array $filter = [];

	/**
	 * The narrowing constraints that took effect.
	 *
	 * @var array<string, mixed>
	 */
	public array $appliedExtraFilter = [];

	/**
	 * The cache key of this run.
	 *
	 * @var array<string, mixed>
	 */
	public array $cacheKey = [];

	/**
	 * The fields the result is grouped by.
	 *
	 * @var array<int, string>
	 */
	public array $groupFields = [];

	/**
	 * Whether the join was spent on the grouping.
	 *
	 * @var boolean
	 */
	public bool $joinConsumed = false;

	/**
	 * Whether the fallback read hit the row cap.
	 *
	 * @var boolean
	 */
	public bool $truncated = false;


	/**
	 * Constructor.
	 *
	 * @param Register $register The register the schema is resolved in.
	 * @param Schema   $schema   The schema being aggregated.
	 * @param string   $name     The aggregation name.
	 */
	public function __construct(
		public readonly Register $register,
		public readonly Schema $schema,
		public readonly string $name,
	) {
	}//end __construct()


	/**
	 * The single metric (`metric`, or its alias `select`).
	 *
	 * @return string
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-19
	 */
	public function getMetric(): string {
		return (string) ($this->spec['metric'] ?? $this->spec['select'] ?? '');
	}//end getMetric()


	/**
	 * The declared `metrics` list, or null when absent or empty.
	 *
	 * @return array<int, array<string, mixed>>|null
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-19
	 */
	public function getMetrics(): ?array {
		$metrics = ($this->spec['metrics'] ?? null);
		if (is_array($metrics) === true && $metrics !== []) {
			return $metrics;
		}

		return null;
	}//end getMetrics()


	/**
	 * The declared `field`, as declared.
	 *
	 * @return mixed
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-19
	 */
	public function getField(): mixed {
		return ($this->spec['field'] ?? null);
	}//end getField()


	/**
	 * The declared `field` when it is a string, otherwise null.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-19
	 */
	public function getStringField(): ?string {
		$field = $this->getField();
		if (is_string($field) === true) {
			return $field;
		}

		return null;
	}//end getStringField()


	/**
	 * The metric field as a list of names: empty when no field is declared.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-19
	 */
	public function getFieldNames(): array {
		if ($this->getField() === null) {
			return [];
		}

		return [(string) $this->getField()];
	}//end getFieldNames()


	/**
	 * The declared `groupBy`, as declared.
	 *
	 * @return mixed
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-19
	 */
	public function getGroupBy(): mixed {
		return ($this->spec['groupBy'] ?? null);
	}//end getGroupBy()


	/**
	 * The declared `groupBy` when it is an array, otherwise null.
	 *
	 * @return array<mixed>|null
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-19
	 */
	public function getGroupByArg(): ?array {
		$groupBy = $this->getGroupBy();
		if (is_array($groupBy) === true) {
			return $groupBy;
		}

		return null;
	}//end getGroupByArg()


	/**
	 * The declared `join`, as declared.
	 *
	 * @return mixed
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-19
	 */
	public function getJoin(): mixed {
		return ($this->spec['join'] ?? null);
	}//end getJoin()


	/**
	 * The declared `join` when it is an array, otherwise null.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-19
	 */
	public function getJoinArg(): ?array {
		$join = $this->getJoin();
		if (is_array($join) === true) {
			return $join;
		}

		return null;
	}//end getJoinArg()


	/**
	 * The declared filter (`filter`, or its alias `where`) before placeholders resolve.
	 *
	 * @return array<mixed>
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-19
	 */
	public function getDeclaredFilter(): array {
		return (array) ($this->spec['filter'] ?? $this->spec['where'] ?? []);
	}//end getDeclaredFilter()


	/**
	 * The source schema of a cross-schema spec, or null for an intra-schema one.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-19
	 */
	public function getFromSchema(): ?string {
		$from = ($this->spec['from'] ?? null);
		if (is_string($from) === true && $from !== '') {
			return $from;
		}

		return null;
	}//end getFromSchema()


	/**
	 * The head of the result envelope for the given backend.
	 *
	 * @param string $backend The backend that computed the figures.
	 *
	 * @return array{name: string, metric: string, field: ?string, backend: string, truncated: bool}
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-19
	 */
	public function envelope(string $backend): array {
		return [
			'name'      => $this->name,
			'metric'    => $this->getMetric(),
			'field'     => $this->getStringField(),
			'backend'   => $backend,
			'truncated' => $this->truncated,
		];
	}//end envelope()
}//end class
