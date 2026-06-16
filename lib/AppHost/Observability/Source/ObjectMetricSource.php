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

use OCA\OpenRegister\AppHost\Observability\MetricDescriptor;
use OCA\OpenRegister\AppHost\Observability\MetricSample;
use OCA\OpenRegister\AppHost\Observability\MetricSourceInterface;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * objectCount / objectSum source via OR's portable aggregation layer.
 */
class ObjectMetricSource implements MetricSourceInterface
{
    /**
     * Constructor.
     *
     * @param ObjectService   $objectService OR object service (aggregation).
     * @param RegisterMapper  $registerMapper Slug → register id.
     * @param SchemaMapper    $schemaMapper   Slug → schema id.
     * @param LoggerInterface $logger         PSR logger.
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function kind(): string
    {
        // This source handles two kinds; the engine routes both here.
        return 'objectCount';
    }//end kind()

    /**
     * {@inheritDoc}
     *
     * @param string           $appId      Calling app id.
     * @param MetricDescriptor $descriptor The metric descriptor.
     *
     * @return MetricSample[]
     */
    public function collect(string $appId, MetricDescriptor $descriptor): array
    {
        $source = $descriptor->source;
        $help   = $descriptor->help ?? sprintf('%s for %s', $descriptor->kind, $source['schema'] ?? 'objects');

        try {
            $baseQuery = $this->buildBaseQuery(source: $source);
        } catch (Throwable $e) {
            $this->logger->warning(
                message: sprintf('[AppHost\\Metrics] objectCount "%s" (app %s) could not resolve register/schema: %s', $descriptor->name, $appId, $e->getMessage()),
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            return [new MetricSample(name: $descriptor->name, type: $descriptor->type, help: $help, samples: [])];
        }

        $groupBy = $source['groupBy'] ?? [];
        $field   = $descriptor->kind === 'objectSum' ? (string) $source['field'] : null;

        if (is_array($groupBy) === true && $groupBy !== []) {
            $samples = $this->collectGrouped(baseQuery: $baseQuery, groupBy: array_values($groupBy), field: $field);
        } else {
            $samples = [['labels' => [], 'value' => $this->aggregate(query: $baseQuery, field: $field)]];
        }

        return [new MetricSample(name: $descriptor->name, type: $descriptor->type, help: $help, samples: $samples)];
    }//end collect()

    /**
     * Build the base search query (register/schema numeric ids + filters).
     *
     * @param array<string, mixed> $source Validated source block.
     *
     * @return array<string, mixed>
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException When the schema slug is unknown.
     */
    private function buildBaseQuery(array $source): array
    {
        $schemaId = $this->schemaMapper->find($source['schema'], null, false, false)->getId();

        $self = ['schema' => $schemaId];
        if (isset($source['register']) === true && $source['register'] !== '') {
            $self['register'] = $this->registerMapper->find($source['register'], null, false, false)->getId();
        }

        $query = ['@self' => $self];

        foreach (($source['filter'] ?? []) as $fieldName => $ops) {
            foreach ($ops as $operator => $value) {
                $this->applyFilter(query: $query, field: (string) $fieldName, operator: (string) $operator, value: $value);
            }
        }

        return $query;
    }//end buildBaseQuery()

    /**
     * Apply one filter operator into the MagicMapper query shape, resolving
     * `now`/`today` date tokens server-side.
     *
     * @param array<string, mixed> $query    Query (by reference).
     * @param string               $field    Object field name.
     * @param string               $operator eq|neq|lt|lte|gt|gte|like.
     * @param mixed                $value    Raw value (may be a date token).
     *
     * @return void
     */
    private function applyFilter(array &$query, string $field, string $operator, mixed $value): void
    {
        $resolved = $this->resolveValue(value: $value);

        switch ($operator) {
            case 'eq':
                $query[$field] = $resolved;
                break;
            case 'neq':
                $query[$field] = ['ne' => $resolved];
                break;
            case 'lt':
            case 'lte':
            case 'gt':
            case 'gte':
                $query[$field]              = ($query[$field] ?? []);
                $query[$field][$operator]   = $resolved;
                break;
            case 'like':
                $query[$field] = ['like' => $resolved];
                break;
        }
    }//end applyFilter()

    /**
     * Resolve a filter value, expanding the `now` / `today` date tokens.
     *
     * @param mixed $value Raw value.
     *
     * @return mixed Resolved value.
     */
    private function resolveValue(mixed $value): mixed
    {
        if ($value === 'now') {
            return (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        }

        if ($value === 'today') {
            return (new \DateTimeImmutable('today'))->format('Y-m-d');
        }

        return $value;
    }//end resolveValue()

    /**
     * Aggregate the query: COUNT, or SUM of a numeric field for objectSum.
     *
     * @param array<string, mixed> $query Search query.
     * @param string|null          $field Numeric field for objectSum (null = count).
     *
     * @return float|int
     */
    private function aggregate(array $query, ?string $field): float|int
    {
        if ($field === null) {
            return $this->objectService->countSearchObjects(query: $query, _rbac: false, _multitenancy: false);
        }

        // objectSum: portable SUM via the search layer, summing the numeric JSON field.
        $objects = $this->objectService->searchObjects(query: $query, _rbac: false, _multitenancy: false);
        $sum     = 0.0;
        foreach ($objects as $object) {
            $data  = is_array($object) === true ? $object : (method_exists($object, 'getObject') === true ? $object->getObject() : []);
            $value = $data[$field] ?? 0;
            if (is_numeric($value) === true) {
                $sum += (float) $value;
            }
        }

        return $sum;
    }//end aggregate()

    /**
     * Collect grouped samples by faceting over the groupBy fields.
     *
     * @param array<string, mixed> $baseQuery Base query.
     * @param string[]             $groupBy   Group-by JSON fields.
     * @param string|null          $field     Numeric field for objectSum.
     *
     * @return array<int, array{labels: array<string,string>, value: float|int}>
     */
    private function collectGrouped(array $baseQuery, array $groupBy, ?string $field): array
    {
        return $this->expandGroups(query: $baseQuery, remaining: $groupBy, labels: [], field: $field);
    }//end collectGrouped()

    /**
     * Recursively expand group-by fields into labelled samples. For each field
     * we facet to discover its distinct values, then descend with each value
     * pinned as an equality filter. Pure OR aggregation — no JSON SQL here.
     *
     * @param array<string, mixed> $query     Current query.
     * @param string[]             $remaining Remaining group-by fields.
     * @param array<string,string> $labels    Accumulated labels.
     * @param string|null          $field     Numeric field for objectSum.
     *
     * @return array<int, array{labels: array<string,string>, value: float|int}>
     */
    private function expandGroups(array $query, array $remaining, array $labels, ?string $field): array
    {
        if ($remaining === []) {
            $value = $this->aggregate(query: $query, field: $field);
            return $value === 0 || $value === 0.0 ? [] : [['labels' => $labels, 'value' => $value]];
        }

        $groupField = array_shift($remaining);
        $buckets    = $this->facetValues(query: $query, field: $groupField);

        $samples = [];
        foreach ($buckets as $bucketValue) {
            $scoped              = $query;
            $scoped[$groupField] = $bucketValue;
            $childLabels         = ($labels + [$groupField => (string) $bucketValue]);
            foreach ($this->expandGroups(query: $scoped, remaining: $remaining, labels: $childLabels, field: $field) as $sample) {
                $samples[] = $sample;
            }
        }

        return $samples;
    }//end expandGroups()

    /**
     * Discover the distinct values of a field via OR's facet layer.
     *
     * @param array<string, mixed> $query Current query.
     * @param string               $field Field to facet.
     *
     * @return array<int, string> Distinct values.
     */
    private function facetValues(array $query, string $field): array
    {
        $facetQuery            = $query;
        $facetQuery['_facets'] = [$field => ['type' => 'terms']];

        $facets = $this->objectService->getFacetsForObjects(query: $facetQuery);

        // External standardized format: { "_<field>": { data: { buckets: [{value,count}] } } }.
        $node    = $facets['_'.$field] ?? $facets[$field] ?? null;
        $buckets = $node['data']['buckets'] ?? $node['buckets'] ?? [];

        $values = [];
        foreach ($buckets as $bucket) {
            $value = $bucket['value'] ?? $bucket['key'] ?? null;
            if ($value !== null && $value !== '') {
                $values[] = (string) $value;
            }
        }

        return $values;
    }//end facetValues()
}//end class
