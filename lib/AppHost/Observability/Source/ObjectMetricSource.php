<?php

/**
 * OpenRegister AppHost — Object Metric Source
 *
 * Executes `objectCount` and `objectSum` metric descriptors through
 * OpenRegister's portable aggregation layer (countSearchObjects / facets) —
 * never dialect-specific JSON_EXTRACT SQL. Resolves register/schema slugs to
 * numeric ids, maps the descriptor's filter operators (with `now`/`today`
 * tokens) into the search query, and turns `groupBy` JSON fields into labels.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\AppHost\Observability\Source
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Observability\Source;

use DateTimeImmutable;
use OCA\OpenRegister\AppHost\SchemaScopeLookups;
use OCA\OpenRegister\AppHost\Observability\MetricDescriptor;
use OCA\OpenRegister\AppHost\Observability\MetricSample;
use OCA\OpenRegister\AppHost\Observability\MetricSourceInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use Throwable;

/**
 * Object-count / object-sum source via OR's portable aggregation layer.
 *
 * @spec openspec/changes/apphost-observability-engine/tasks.md#task-3.2
 */
class ObjectMetricSource implements MetricSourceInterface {

	/**
	 * Operators that replace the field's constraint with `{<key>: value}`.
	 *
	 * @var array<string, string>
	 */
	private const WRAPPED_OPERATORS = ['neq' => 'ne', 'like' => 'like'];

	/**
	 * Operators that add `{<operator>: value}` to the field's range constraint.
	 *
	 * @var string[]
	 */
	private const RANGE_OPERATORS = ['lt', 'lte', 'gt', 'gte'];
	/**
	 * Constructor.
	 *
	 * @param ObjectOperationsInterface $objectService OR object service (aggregation).
	 * @param SchemaScopeLookups        $lookups       Slug → register/schema id, and the PSR logger.
	 */
	public function __construct(
		private readonly ObjectOperationsInterface $objectService,
		private readonly SchemaScopeLookups $lookups,
	) {
	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 *
	 * @spec openspec/changes/apphost-observability-engine/tasks.md#task-3.2
	 */
	public function kind(): string {
		// This source handles two kinds; the engine routes both here.
		return 'objectCount';
	}//end kind()

	/**
	 * {@inheritDoc}
	 *
	 * @param string $appId Calling app id.
	 * @param MetricDescriptor $descriptor The metric descriptor.
	 *
	 * @return MetricSample[]
	 *
	 * @spec openspec/changes/apphost-observability-engine/tasks.md#task-3.2
	 */
	public function collect(string $appId, MetricDescriptor $descriptor): array {
		$source = $descriptor->source;
		$help   = $descriptor->help ?? sprintf('%s for %s', $descriptor->kind, $source['schema'] ?? 'objects');

		$baseQuery = $this->resolvedBaseQuery(appId: $appId, descriptor: $descriptor);
		if ($baseQuery === null) {
			return [new MetricSample(name: $descriptor->name, type: $descriptor->type, help: $help, samples: [])];
		}

		$samples = $this->samplesFor(descriptor: $descriptor, baseQuery: $baseQuery);
		return [new MetricSample(name: $descriptor->name, type: $descriptor->type, help: $help, samples: $samples)];
	}//end collect()

	/**
	 * The base query of a descriptor; null, logged, when its register or schema does not resolve.
	 *
	 * @param string           $appId      Calling app id.
	 * @param MetricDescriptor $descriptor The metric descriptor.
	 *
	 * @return array<string, mixed>|null
	 */
	private function resolvedBaseQuery(string $appId, MetricDescriptor $descriptor): ?array {
		try {
			return $this->buildBaseQuery(source: $descriptor->source);
		} catch (Throwable $e) {
			$logMessage = sprintf(
				'[AppHost\\Metrics] objectCount "%s" (app %s) could not resolve register/schema: %s',
				$descriptor->name,
				$appId,
				$e->getMessage()
			);
			$this->warn(message: $logMessage);
			return null;
		}
	}//end resolvedBaseQuery()

	/**
	 * Log a collection failure as a warning.
	 *
	 * @param string $message What failed.
	 *
	 * @return void
	 */
	private function warn(string $message): void {
		$this->lookups->logger->warning(
			message: $message,
			context: ['file' => __FILE__, 'line' => __LINE__]
		);
	}//end warn()

	/**
	 * The samples of a descriptor: one aggregate, or one per group.
	 *
	 * @param MetricDescriptor     $descriptor The metric descriptor.
	 * @param array<string, mixed> $baseQuery  The resolved base query.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function samplesFor(MetricDescriptor $descriptor, array $baseQuery): array {
		$groupBy = $descriptor->source['groupBy'] ?? [];
		$field   = null;
		if ($descriptor->kind === 'objectSum') {
			$field = (string)$descriptor->source['field'];
		}

		if (is_array($groupBy) === true && $groupBy !== []) {
			$buckets = $this->bucketsFor(baseQuery: $baseQuery, groupBy: array_values($groupBy));
			return $this->groupedSamples(buckets: $buckets, field: $field);
		}

		return [['labels' => [], 'value' => $this->aggregate(query: $baseQuery, field: $field)]];
	}//end samplesFor()

	/**
	 * Build the base search query (register/schema numeric ids + filters).
	 *
	 * @param array<string, mixed> $source Validated source block.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When the schema slug is unknown.
	 */
	private function buildBaseQuery(array $source): array {
		$schemaId = $this->lookups->schemaMapper->find($source['schema'], _rbac: false, _multitenancy: false)->getId();

		$self = ['schema' => $schemaId];
		if (isset($source['register']) === true && $source['register'] !== '') {
			$registerId = $this->lookups->registerMapper->find($source['register'], _rbac: false, _multitenancy: false)->getId();
			$self['register'] = $registerId;
		}

		$query = ['@self' => $self];

		foreach (($source['filter'] ?? []) as $fieldName => $ops) {
			foreach ($ops as $operator => $value) {
				$filter = new ObjectMetricFilter(field: (string)$fieldName, operator: (string)$operator, value: $value);
				$query  = $this->applyFilter(query: $query, filter: $filter);
			}
		}

		return $query;
	}//end buildBaseQuery()

	/**
	 * Apply one filter operator into the MagicMapper query shape, resolving
	 * `now`/`today` date tokens server-side. An unknown operator adds nothing.
	 *
	 * @param array<string, mixed> $query  The query so far.
	 * @param ObjectMetricFilter   $filter The field, operator (eq|neq|lt|lte|gt|gte|like) and raw value.
	 *
	 * @return array<string, mixed> The query with the filter applied.
	 */
	private function applyFilter(array $query, ObjectMetricFilter $filter): array {
		$field    = $filter->field;
		$operator = $filter->operator;
		$resolved = $this->resolveValue(value: $filter->value);
		if ($operator === 'eq') {
			$query[$field] = $resolved;
		}

		if (isset(self::WRAPPED_OPERATORS[$operator]) === true) {
			$query[$field] = [self::WRAPPED_OPERATORS[$operator] => $resolved];
		}

		if (in_array($operator, self::RANGE_OPERATORS, true) === true) {
			$query[$field] = ($query[$field] ?? []);
			$query[$field][$operator] = $resolved;
		}

		return $query;
	}//end applyFilter()

	/**
	 * Resolve a filter value, expanding the `now` / `today` date tokens.
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return mixed Resolved value.
	 */
	private function resolveValue(mixed $value): mixed {
		if ($value === 'now') {
			return (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
		}

		if ($value === 'today') {
			return (new DateTimeImmutable('today'))->format('Y-m-d');
		}

		return $value;
	}//end resolveValue()

	/**
	 * Aggregate the query: COUNT, or SUM of a numeric field for objectSum.
	 *
	 * @param array<string, mixed> $query Search query.
	 * @param string|null $field Numeric field for objectSum (null = count).
	 *
	 * @return float|int
	 */
	private function aggregate(array $query, ?string $field): float|int {
		if ($field === null) {
			return $this->objectService->countSearchObjects(query: $query, _rbac: false, _multitenancy: false);
		}

		// The objectSum kind sums a numeric JSON field portably via the search
		// layer (no dialect-specific SUM SQL leaks into this engine).
		$objects = $this->objectService->searchObjects(query: $query, _rbac: false, _multitenancy: false);
		$sum = 0.0;
		foreach ($objects as $object) {
			$data = $this->extractObjectData(object: $object);
			$value = $data[$field] ?? 0;
			if (is_numeric($value) === true) {
				$sum += (float)$value;
			}
		}

		return $sum;
	}//end aggregate()

	/**
	 * Extract the data array from a search result row, which may be a plain
	 * array or an ObjectEntity exposing getObject().
	 *
	 * @param mixed $object Search result row.
	 *
	 * @return array<string, mixed>
	 */
	private function extractObjectData(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'getObject') === true) {
			$data = $object->getObject();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return [];
	}//end extractObjectData()

	/**
	 * Expand group-by fields into labelled buckets. For each field we facet
	 * every bucket so far to discover its distinct values, and split it into
	 * one bucket per value, pinned as an equality filter. Buckets keep the
	 * depth-first order of the fields and values. Pure OR aggregation: no
	 * JSON SQL here.
	 *
	 * @param array<string, mixed> $baseQuery Base query.
	 * @param string[]             $groupBy   Group-by JSON fields.
	 *
	 * @return array<int, array{query: array<string, mixed>, labels: array<string, string>}>
	 */
	private function bucketsFor(array $baseQuery, array $groupBy): array {
		$buckets = [['query' => $baseQuery, 'labels' => []]];
		foreach ($groupBy as $groupField) {
			$buckets = $this->split(buckets: $buckets, groupField: $groupField);
		}

		return $buckets;
	}//end bucketsFor()

	/**
	 * Split every bucket into one bucket per distinct value of a field.
	 *
	 * @param array<int, array{query: array<string, mixed>, labels: array<string, string>}> $buckets    The buckets so far.
	 * @param string                                                                          $groupField The field to split on.
	 *
	 * @return array<int, array{query: array<string, mixed>, labels: array<string, string>}>
	 */
	private function split(array $buckets, string $groupField): array {
		$children = [];
		foreach ($buckets as $bucket) {
			foreach ($this->facetValues(query: $bucket['query'], field: $groupField) as $bucketValue) {
				$scoped = $bucket['query'];
				$scoped[$groupField] = $bucketValue;
				$children[] = ['query' => $scoped, 'labels' => ($bucket['labels'] + [$groupField => (string)$bucketValue])];
			}
		}

		return $children;
	}//end split()

	/**
	 * The labelled samples of the buckets, dropping the ones that aggregate to zero.
	 *
	 * @param array<int, array{query: array<string, mixed>, labels: array<string, string>}> $buckets The fully split buckets.
	 * @param string|null                                                                     $field   Numeric field for objectSum.
	 *
	 * @return array<int, array{labels: array<string,string>, value: float|int}>
	 */
	private function groupedSamples(array $buckets, ?string $field): array {
		$samples = [];
		foreach ($buckets as $bucket) {
			$value = $this->aggregate(query: $bucket['query'], field: $field);
			if ($value !== 0 && $value !== 0.0) {
				$samples[] = ['labels' => $bucket['labels'], 'value' => $value];
			}
		}

		return $samples;
	}//end groupedSamples()

	/**
	 * Discover the distinct values of a field via OR's facet layer.
	 *
	 * @param array<string, mixed> $query Current query.
	 * @param string $field Field to facet.
	 *
	 * @return array<int, string> Distinct values.
	 */
	private function facetValues(array $query, string $field): array {
		$facetQuery = $query;
		$facetQuery['_facets'] = [$field => ['type' => 'terms']];

		$facets = $this->objectService->getFacetsForObjects(query: $facetQuery);

		// External standardized format: { "_<field>": { data: { buckets: [{value,count}] } } }.
		$node = $facets['_' . $field] ?? $facets[$field] ?? null;
		$buckets = $node['data']['buckets'] ?? $node['buckets'] ?? [];

		$values = [];
		foreach ($buckets as $bucket) {
			$value = $bucket['value'] ?? $bucket['key'] ?? null;
			if ($value !== null && $value !== '') {
				$values[] = (string)$value;
			}
		}

		return $values;
	}//end facetValues()
}//end class
