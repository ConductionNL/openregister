<?php

/**
 * OpenRegister CrossSchemaAggregationRun
 *
 * The state of one cross-schema (`from`) aggregation: the named run it came
 * from, the parent row for `@self.<field>` references, and what the run
 * derives from them (the target schema and register, the resolved `where`
 * clause, the cache key).
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
 * Carries a cross-schema aggregation through AggregationRunner.
 *
 * The cross-schema path reads the spec with its own defaults, which differ
 * from the intra-schema ones on NamedAggregationRun: the metric defaults to
 * `count`, and `where` wins over its alias `filter`.
 *
 * @spec openspec/specs/aggregations-backend-native/spec.md
 */
final class CrossSchemaAggregationRun {

	/**
	 * The session user, as read when the target was gated.
	 *
	 * @var string|null
	 */
	public ?string $userId = null;

	/**
	 * The `where` clause with `@self.*` references and placeholders resolved.
	 *
	 * @var array<string, mixed>
	 */
	public array $where = [];

	/**
	 * The cache key for this run.
	 *
	 * @var array<string, mixed>
	 */
	public array $cacheKey = [];

	/**
	 * Whether the PHP fallback hit its row cap.
	 *
	 * @var bool
	 */
	public bool $truncated = false;

	/**
	 * Constructor.
	 *
	 * @param NamedAggregationRun  $parent         The named run on the parent schema.
	 * @param array<string, mixed> $parentRow      Parent object fields for `@self.*` resolution.
	 * @param Schema               $targetSchema   The schema named by `from`.
	 * @param Register             $targetRegister The register that carries the target schema.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/aggregations-backend-native/spec.md
	 */
	public function __construct(
		public readonly NamedAggregationRun $parent,
		public readonly array $parentRow,
		public readonly Schema $targetSchema,
		public readonly Register $targetRegister,
	) {
	}//end __construct()

	/**
	 * The `from` reference, or an empty string when absent.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/aggregations-backend-native/spec.md
	 */
	public function getFromRef(): string {
		return (string) ($this->parent->spec['from'] ?? '');
	}//end getFromRef()

	/**
	 * The declared metric (`metric`, or its alias `select`), `count` by default.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/aggregations-backend-native/spec.md
	 */
	public function getMetric(): string {
		return (string) ($this->parent->spec['metric'] ?? $this->parent->spec['select'] ?? 'count');
	}//end getMetric()

	/**
	 * The declared `metrics` list, or null when absent or empty.
	 *
	 * @return array<int, mixed>|null
	 *
	 * @spec openspec/specs/aggregations-backend-native/spec.md
	 */
	public function getMetrics(): ?array {
		return $this->parent->getMetrics();
	}//end getMetrics()

	/**
	 * The declared `field`, as declared.
	 *
	 * @return mixed
	 *
	 * @spec openspec/specs/aggregations-backend-native/spec.md
	 */
	public function getField(): mixed {
		return $this->parent->getField();
	}//end getField()

	/**
	 * The declared `field` when it is a string, otherwise null.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/aggregations-backend-native/spec.md
	 */
	public function getStringField(): ?string {
		return $this->parent->getStringField();
	}//end getStringField()

	/**
	 * The declared `groupBy`, as declared.
	 *
	 * @return mixed
	 *
	 * @spec openspec/specs/aggregations-backend-native/spec.md
	 */
	public function getGroupBy(): mixed {
		return $this->parent->getGroupBy();
	}//end getGroupBy()

	/**
	 * The declared `groupBy` when it is an array, otherwise null.
	 *
	 * @return array<mixed>|null
	 *
	 * @spec openspec/specs/aggregations-backend-native/spec.md
	 */
	public function getGroupByArg(): ?array {
		return $this->parent->getGroupByArg();
	}//end getGroupByArg()

	/**
	 * The declared clause (`where`, or its alias `filter`) before resolution.
	 *
	 * @return array<mixed>
	 *
	 * @spec openspec/specs/aggregations-backend-native/spec.md
	 */
	public function getRawWhere(): array {
		return (array) ($this->parent->spec['where'] ?? $this->parent->spec['filter'] ?? []);
	}//end getRawWhere()

	/**
	 * The result envelope's leading keys for the given backend.
	 *
	 * @param string $backend The backend that computed the figures.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/aggregations-backend-native/spec.md
	 */
	public function envelope(string $backend): array {
		return [
			'name'      => $this->parent->name,
			'metric'    => $this->getMetric(),
			'field'     => $this->getStringField(),
			'from'      => $this->getFromRef(),
			'backend'   => $backend,
			'truncated' => $this->truncated,
		];
	}//end envelope()
}//end class
