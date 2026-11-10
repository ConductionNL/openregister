<?php

/**
 * OpenRegister QualityStatisticsService
 *
 * Read-only aggregation/query surface over the already-materialised
 * `qualityScore` / `qualityStatus` fields written by
 * {@see \OCA\OpenRegister\Listener\QualityScoreOnSaveListener}. Computes, for
 * a `(register, schema)` pair: an average score, per-status bucket counts, a
 * fixed 10-bucket score-distribution histogram over `[0,1]`, and a total; and
 * serves a lowest-quality (ascending score) listing with optional
 * `qualityStatus` filtering, sort, and pagination.
 *
 * This service never re-scores objects and never reimplements the
 * good/fair/poor boundaries — bucket assignment is delegated verbatim to
 * {@see QualityScorer::status()} using the schema's declared
 * `x-openregister-quality` `thresholds`, exactly like the on-save listener
 * does, so the surface's buckets can never drift from the materialised
 * `qualityStatus` (ADR-011). All object reads flow through
 * {@see \OCA\OpenRegister\Service\ObjectService::findAll()} /
 * {@see \OCA\OpenRegister\Service\ObjectService::count()} with RBAC and
 * multitenancy scoping on, mirroring
 * {@see \OCA\OpenRegister\Search\Service\Quality\DuplicateDetectionService::loadCandidates()}
 * (ADR-022, ADR-045).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Quality
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/mdm-surface-api/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Quality;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\RegisterScopedSchemaResolver;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Computes read-time quality statistics and serves the lowest-quality listing.
 *
 * @spec openspec/changes/mdm-surface-api/tasks.md#task-1
 */
class QualityStatisticsService implements QualityStatisticsServiceInterface {
	/**
	 * Default field the score is read from when the annotation omits `field`.
	 *
	 * Mirrors {@see \OCA\OpenRegister\Listener\QualityScoreOnSaveListener::DEFAULT_FIELD}
	 * so the surface reads the same field the listener wrote.
	 *
	 * @var string
	 */
	private const DEFAULT_FIELD = 'qualityScore';

	/**
	 * Upper bound on the object set pulled per statistics computation.
	 *
	 * Mirrors {@see \OCA\OpenRegister\Search\Service\Quality\DuplicateDetectionService::MAX_CANDIDATES} — scopes every
	 * read to a bounded, RBAC-scoped set rather than the whole schema.
	 *
	 * @var int
	 */
	private const MAX_OBJECTS = 1000;

	/**
	 * Number of equal-width histogram buckets spanning `[0, 1]`.
	 *
	 * @var int
	 */
	private const HISTOGRAM_BUCKETS = 10;

	/**
	 * The shared register-scoped schema resolver.
	 *
	 * Built here rather than injected: it is a stateless collaborator over the
	 * `RegisterMapper` + `SchemaMapper` this class already holds, so constructing
	 * it directly keeps every existing unit test — all of which mock those two
	 * mappers — exercising the REAL resolution path instead of a mock of the very
	 * thing under test.
	 *
	 * @var RegisterScopedSchemaResolver
	 */
	private readonly RegisterScopedSchemaResolver $scopedSchemaResolver;

	/**
	 * Wire collaborators.
	 *
	 * @param ObjectOperationsInterface $objectService Object query path (RBAC + tenant scoped).
	 * @param QualityScopeMappers $mappers Schema and register lookups — the register is the boundary the schema resolves inside.
	 * @param QualityScorer $scorer Reused for status() bucketing — never reimplemented.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/mdm-surface-api/tasks.md#task-1
	 */
	public function __construct(
		private readonly ObjectOperationsInterface $objectService,
		QualityScopeMappers $mappers,
		private readonly QualityScorer $scorer,
		private readonly LoggerInterface $logger,
	) {
		$this->scopedSchemaResolver = new RegisterScopedSchemaResolver(
			registerMapper: $mappers->registerMapper,
			schemaMapper: $mappers->schemaMapper
		);
	}//end __construct()


	/**
	 * Compute quality statistics for a register/schema.
	 *
	 * @param int|string $register Register id, uuid or slug.
	 * @param int|string $schema Schema id, uuid or slug.
	 *
	 * @return array{
	 *   average: float|null,
	 *   total: int,
	 *   buckets: array{good: int, fair: int, poor: int},
	 *   histogram: array<int, array{min: float, max: float, count: int}>
	 * } Statistics envelope. Zeroed (null average, zero counts) when the
	 *   scoped set is empty.
	 *
	 * @spec openspec/changes/mdm-surface-api/tasks.md#task-1
	 */
	public function statisticsFor($register, $schema): array {
		$quality = $this->loadAnnotation(register: $register, schema: $schema);
		$field = $this->scoreField(quality: $quality);
		$thresholds = $this->thresholds(quality: $quality);

		$objects = $this->loadObjects(register: $register, schema: $schema);

		$histogram = $this->emptyHistogram();
		$buckets = [
			'good' => 0,
			'fair' => 0,
			'poor' => 0,
		];

		$sum = 0.0;
		$total = 0;

		foreach ($objects as $object) {
			$score = $this->scoreOf(object: $object, field: $field);
			if ($score === null) {
				continue;
			}

			$total++;
			$sum += $score;

			$status = $this->scorer->status(score: $score, thresholds: $thresholds);
			if (isset($buckets[$status]) === true) {
				$buckets[$status]++;
			}

			$index = $this->histogramIndex(score: $score);
			$histogram[$index]['count']++;
		}//end foreach

		$average = null;
		if ($total > 0) {
			$average = round(($sum / $total), 4);
		}

		return [
			'average' => $average,
			'total' => $total,
			'buckets' => $buckets,
			'histogram' => array_values($histogram),
		];
	}//end statisticsFor()

	/**
	 * List objects of a register/schema ascending by quality score.
	 *
	 * @param QualityListingRequest $request The register and schema (id, uuid or slug), the optional status filter
	 *                                       (`good`/`fair`/`poor`) and the window: sort field and order, page size and offset.
	 *
	 * @return array{
	 *   items: array<int, array{id: string, qualityScore: float|null, qualityStatus: string|null}>,
	 *   total: int,
	 *   limit: int,
	 *   offset: int
	 * } Paginated listing, worst-first by default.
	 *
	 * @spec openspec/changes/mdm-surface-api/tasks.md#task-1
	 */
	public function lowestQuality(QualityListingRequest $request): array {
		$rows = $this->statusRows(request: $request);
		$this->sortRows(rows: $rows, window: $request->window);

		return self::page(rows: $rows, window: $request->window);
	}//end lowestQuality()

	/**
	 * Every loaded object's listing row, scored under the schema's annotation, filtered by status.
	 *
	 * @param QualityListingRequest $request The register, schema and status filter.
	 *
	 * @return array<int, array{id: string, qualityScore: float|null, qualityStatus: string|null}> The rows.
	 *
	 * @spec openspec/changes/mdm-surface-api/tasks.md#task-1
	 */
	private function statusRows(QualityListingRequest $request): array {
		$quality = $this->loadAnnotation(register: $request->register, schema: $request->schema);
		$reading = new QualityScoreReading(field: $this->scoreField(quality: $quality), thresholds: $this->thresholds(quality: $quality));
		$objects = $this->loadObjects(register: $request->register, schema: $request->schema);

		return $this->withStatus(rows: $this->listingRows(objects: $objects, reading: $reading), qualityStatus: $request->qualityStatus);
	}//end statusRows()

	/**
	 * One window of sorted rows, with the total before paging.
	 *
	 * @param array<int, array<string, mixed>> $rows   The sorted rows.
	 * @param QualityListingWindow             $window The page size and offset.
	 *
	 * @return array{items: array<int, array<string, mixed>>, total: int, limit: int, offset: int} The page.
	 *
	 * @spec openspec/changes/mdm-surface-api/tasks.md#task-1
	 */
	private static function page(array $rows, QualityListingWindow $window): array {
		return [
			'items' => array_slice($rows, max(0, $window->offset), max(0, $window->limit)),
			'total' => count($rows),
			'limit' => $window->limit,
			'offset' => $window->offset,
		];
	}//end page()

	/**
	 * Build one listing row per object.
	 *
	 * @param array<int, ObjectEntity> $objects Objects in the register/schema.
	 * @param QualityScoreReading      $reading Where the score lives and its status cut-offs.
	 *
	 * @return array<int, array{id: string, qualityScore: float|null, qualityStatus: string|null}>
	 */
	private function listingRows(array $objects, QualityScoreReading $reading): array {
		$rows = [];
		foreach ($objects as $object) {
			$rows[] = $this->listingRow(object: $object, reading: $reading);
		}

		return $rows;
	}//end listingRows()

	/**
	 * Keep only the rows matching the status filter, in order.
	 *
	 * @param array<int, array{id: string, qualityScore: float|null, qualityStatus: string|null}> $rows          The rows.
	 * @param string|null                                                                          $qualityStatus Optional status filter;
	 *                                                                                                            null or '' keeps every row.
	 *
	 * @return array<int, array{id: string, qualityScore: float|null, qualityStatus: string|null}>
	 */
	private function withStatus(array $rows, ?string $qualityStatus): array {
		$kept = [];
		foreach ($rows as $row) {
			if ($this->matchesStatus(status: $row['qualityStatus'], qualityStatus: $qualityStatus) === true) {
				$kept[] = $row;
			}
		}

		return $kept;
	}//end withStatus()

	/**
	 * One listing row: the object's id, score and status.
	 *
	 * @param ObjectEntity        $object  Object entity.
	 * @param QualityScoreReading $reading Where the score lives and its status cut-offs.
	 *
	 * @return array{id: string, qualityScore: float|null, qualityStatus: string|null}
	 */
	private function listingRow(ObjectEntity $object, QualityScoreReading $reading): array {
		$score = $this->scoreOf(object: $object, field: $reading->field);
		$status = null;
		if ($score !== null) {
			$status = $this->scorer->status(score: $score, thresholds: $reading->thresholds);
		}

		return [
			'id' => (string)$object->getUuid(),
			'qualityScore' => $score,
			'qualityStatus' => $status,
		];
	}//end listingRow()

	/**
	 * Whether a row's status passes the status filter.
	 *
	 * @param string|null $status The row's status; null when unscored.
	 * @param string|null $qualityStatus Optional filter; null or '' passes every row.
	 *
	 * @return bool
	 */
	private function matchesStatus(?string $status, ?string $qualityStatus): bool {
		return $qualityStatus === null || $qualityStatus === '' || $status === $qualityStatus;
	}//end matchesStatus()

	/**
	 * Sort listing rows in place by the requested field/order.
	 *
	 * @param array<int, array{id: string, qualityScore: float|null, qualityStatus: string|null}> $rows   Rows to sort (by reference).
	 * @param QualityListingWindow                                                                 $window The sort field and order.
	 *
	 * @return void
	 */
	private function sortRows(array &$rows, QualityListingWindow $window): void {
		$direction = $this->sortDirection(order: $window->order);
		$key = 'qualityScore';
		if ($window->sort === 'qualityStatus') {
			$key = 'qualityStatus';
		}

		usort(
			$rows,
			static fn (array $left, array $right): int => (self::nullsLastOrder(leftValue: $left[$key], rightValue: $right[$key])
				?? ($direction * self::valueOrder(leftValue: $left[$key], rightValue: $right[$key])))
		);
	}//end sortRows()

	/**
	 * The comparison sign for a sort order: -1 for `desc` (any case), else 1.
	 *
	 * @param string $order Sort order.
	 *
	 * @return int
	 */
	private function sortDirection(string $order): int {
		if (strtolower($order) === 'desc') {
			return -1;
		}

		return 1;
	}//end sortDirection()

	/**
	 * The order of two sort values when they are equal or either is null:
	 * nulls (unscored objects) sort last regardless of direction.
	 *
	 * @param float|string|null $leftValue  Left value.
	 * @param float|string|null $rightValue Right value.
	 *
	 * @return int|null The order, or null when both are set and differ.
	 */
	private static function nullsLastOrder($leftValue, $rightValue): ?int {
		if ($leftValue === $rightValue) {
			return 0;
		}

		if ($leftValue === null) {
			return 1;
		}

		if ($rightValue === null) {
			return -1;
		}

		return null;
	}//end nullsLastOrder()

	/**
	 * The ascending order of two differing, non-null sort values.
	 *
	 * @param float|string $leftValue  Left value.
	 * @param float|string $rightValue Right value.
	 *
	 * @return int -1 when left sorts first, else 1.
	 */
	private static function valueOrder($leftValue, $rightValue): int {
		if ($leftValue < $rightValue) {
			return -1;
		}

		return 1;
	}//end valueOrder()

	/**
	 * Load the RBAC + tenant scoped object set for a register/schema.
	 *
	 * @param int|string $register Register reference.
	 * @param int|string $schema Schema reference.
	 *
	 * @return array<int, ObjectEntity>
	 */
	private function loadObjects($register, $schema): array {
		try {
			$objects = $this->objectService->findAll(
				[
					'filters' => [
						'register' => $register,
						'schema' => $schema,
					],
					'limit' => self::MAX_OBJECTS,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning('Quality statistics object load failed: ' . $e->getMessage());
			return [];
		}

		$entities = [];
		foreach ($objects as $object) {
			if ($object instanceof ObjectEntity) {
				$entities[] = $object;
			}
		}

		return $entities;
	}//end loadObjects()

	/**
	 * Read the `x-openregister-quality` annotation off a schema.
	 *
	 * Mirrors {@see \OCA\OpenRegister\Search\Service\Quality\DuplicateDetectionService::loadAnnotation()}.
	 *
	 * REGISTER-SCOPED. `GET /api/objects/quality/{register}/{schema}` has always
	 * carried a register, and this lookup used to ignore it and resolve the slug
	 * globally — so on any instance where two apps share a schema slug the quality
	 * thresholds of ANOTHER app's schema were applied to this register's rows,
	 * silently changing every good/fair/poor verdict. The object set itself is
	 * loaded with both refs, so the annotation was the only half that drifted.
	 *
	 * A miss still degrades to `[]` (defaults) rather than throwing, matching the
	 * pre-existing contract of this method: the statistics endpoint reports on
	 * whatever rows it finds and an absent annotation is a normal state.
	 *
	 * @param int|string $register Register reference — the boundary.
	 * @param int|string $schema   Schema reference.
	 *
	 * @return array<string, mixed> Annotation (empty array when absent / unresolvable).
	 *
	 * @spec openspec/specs/register-scoped-slug-resolution/spec.md
	 */
	private function loadAnnotation($register, $schema): array {
		try {
			$entity = $this->scopedSchemaResolver->resolvePair(
				registerRef: $register,
				schemaRef: $schema
			)['schema'];
		} catch (Throwable $e) {
			return [];
		}

		// No instanceof re-check: the lookup above is declared to return Schema
		// and throws otherwise, which the catch already handles.
		$config = ($entity->getConfiguration() ?? []);
		$annotation = ($config['x-openregister-quality'] ?? null);
		if (is_array($annotation) === true) {
			return $annotation;
		}

		return [];
	}//end loadAnnotation()

	/**
	 * Resolve the payload field the quality score was materialised into.
	 *
	 * @param array<string, mixed> $quality Quality annotation.
	 *
	 * @return string Field name, defaulting to `qualityScore`.
	 */
	private function scoreField(array $quality): string {
		$field = (string)($quality['field'] ?? self::DEFAULT_FIELD);
		if ($field === '') {
			return self::DEFAULT_FIELD;
		}

		return $field;
	}//end scoreField()

	/**
	 * Resolve the status thresholds declared on the quality annotation.
	 *
	 * @param array<string, mixed> $quality Quality annotation.
	 *
	 * @return array<string, mixed> Thresholds map (possibly empty — QualityScorer
	 *                              falls back to its own defaults).
	 */
	private function thresholds(array $quality): array {
		$thresholds = ($quality['thresholds'] ?? []);
		if (is_array($thresholds) === true) {
			return $thresholds;
		}

		return [];
	}//end thresholds()

	/**
	 * Read the materialised score off an object's payload.
	 *
	 * @param ObjectEntity $object Object entity.
	 * @param string $field Payload field the score lives at.
	 *
	 * @return float|null The score, or null when absent / non-numeric.
	 */
	private function scoreOf(ObjectEntity $object, string $field): ?float {
		$data = ($object->getObject() ?? []);
		$value = ($data[$field] ?? null);
		// Ints, floats and numeric strings: exactly what is_numeric() accepts.
		if (is_numeric($value) === true) {
			return (float)$value;
		}

		return null;
	}//end scoreOf()

	/**
	 * Build an empty 10-bucket histogram scaffold over `[0, 1]`.
	 *
	 * @return array<int, array{min: float, max: float, count: int}>
	 */
	private function emptyHistogram(): array {
		$histogram = [];
		$width = (1.0 / self::HISTOGRAM_BUCKETS);

		for ($i = 0; $i < self::HISTOGRAM_BUCKETS; $i++) {
			$histogram[$i] = [
				'min' => round(($i * $width), 4),
				'max' => round((($i + 1) * $width), 4),
				'count' => 0,
			];
		}

		return $histogram;
	}//end emptyHistogram()

	/**
	 * Resolve which histogram bucket a score falls into.
	 *
	 * Buckets are `[min, max)` half-open, except the final bucket which is
	 * closed on both ends so a perfect `1.0` score is not dropped.
	 *
	 * @param float $score Score in `[0, 1]`.
	 *
	 * @return int Bucket index in `[0, HISTOGRAM_BUCKETS - 1]`.
	 */
	private function histogramIndex(float $score): int {
		$clamped = max(0.0, min(1.0, $score));
		$index = (int)floor($clamped * self::HISTOGRAM_BUCKETS);

		if ($index >= self::HISTOGRAM_BUCKETS) {
			$index = (self::HISTOGRAM_BUCKETS - 1);
		}

		return $index;
	}//end histogramIndex()
}//end class
