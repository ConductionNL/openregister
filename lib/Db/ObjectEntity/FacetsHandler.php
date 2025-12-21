<?php

/**
 * FacetsHandler
 *
 * Handler for facet operations on ObjectEntity.
 * Extracted from ObjectEntityMapper to follow Single Responsibility Principle.
 *
 * @category Nextcloud
 * @package  OpenRegister
 * @author   Conduction BV <info@conduction.nl>
 * @license  EUPL-1.2 https://opensource.org/licenses/EUPL-1.2
 * @link     https://www.conduction.nl
 */

namespace OCA\OpenRegister\Db\ObjectEntity;

use OCA\OpenRegister\Db\ObjectHandlers\MariaDbFacetHandler;
use OCA\OpenRegister\Db\ObjectHandlers\MetaDataFacetHandler;
use OCA\OpenRegister\Db\SchemaMapper;
use Psr\Log\LoggerInterface;

/**
 * Handles facet operations for ObjectEntity.
 *
 * This handler manages:
 * - Simple facets using facet handlers
 * - Facetable field discovery from schemas
 * - Facet configuration generation
 *
 * @category Nextcloud
 * @package  OpenRegister
 * @author   Conduction BV <info@conduction.nl>
 * @license  EUPL-1.2 https://opensource.org/licenses/EUPL-1.2
 * @link     https://www.conduction.nl
 */
class FacetsHandler
{

    /**
     * Logger instance.
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Metadata facet handler.
     *
     * @var MetaDataFacetHandler|null
     */
    private ?MetaDataFacetHandler $metaDataFacetHandler;

    /**
     * MariaDB facet handler.
     *
     * @var MariaDbFacetHandler|null
     */
    private ?MariaDbFacetHandler $mariaDbFacetHandler;

    /**
     * Schema mapper for schema operations.
     *
     * @var SchemaMapper
     */
    private SchemaMapper $schemaMapper;

    /**
     * Constructor.
     *
     * @param LoggerInterface           $logger               Logger instance.
     * @param SchemaMapper              $schemaMapper         Schema mapper instance.
     * @param MetaDataFacetHandler|null $metaDataFacetHandler Metadata facet handler (optional).
     * @param MariaDbFacetHandler|null  $mariaDbFacetHandler  MariaDB facet handler (optional).
     */
    public function __construct(
        LoggerInterface $logger,
        SchemaMapper $schemaMapper,
        ?MetaDataFacetHandler $metaDataFacetHandler=null,
        ?MariaDbFacetHandler $mariaDbFacetHandler=null
    ) {
        $this->logger       = $logger;
        $this->schemaMapper = $schemaMapper;
        $this->metaDataFacetHandler = $metaDataFacetHandler;
        $this->mariaDbFacetHandler  = $mariaDbFacetHandler;

    }//end __construct()

    /**
     * Get simple facets using the new handlers.
     *
     * This method provides a simple interface to the new facet handlers.
     * It supports basic terms facets for both metadata and object fields.
     *
     * @param array $query The search query array containing filters and facet configuration.
     *                     - _facets: Simple facet configuration
     *                     - @self: Metadata field facets
     *                     - Direct keys: Object field facets
     *
     * @throws \OCP\DB\Exception If a database error occurs.
     *
     * @return ((((int|mixed|string)[]|int|mixed|string)[]|mixed|string)[]|mixed|string)[][] Simple facet data using the new handlers.
     *
     * @psalm-return array<array<array{type?: 'date_histogram'|'range'|'terms', buckets?: list{0?: array{key: mixed|string, results: int, from?: mixed, to?: mixed, label?: string}|mixed,...}, interval?: string, 0?: array{key: mixed|string, results: int, from?: mixed, to?: mixed}|mixed,...}|mixed|string>>
     */
    public function getSimpleFacets(array $query=[]): array
    {
        // Check if handlers are available.
        if ($this->metaDataFacetHandler === null || $this->mariaDbFacetHandler === null) {
            return [];
        }

        // Extract facet configuration.
        $facetConfig = $query['_facets'] ?? [];
        if (empty($facetConfig) === true) {
            return [];
        }

        // Extract base query (without facet config).
        $baseQuery = $query;
        unset($baseQuery['_facets']);

        $facets = [];

        // Process metadata facets (@self).
        if (($facetConfig['@self'] ?? null) !== null && is_array($facetConfig['@self']) === true) {
            $facets['@self'] = [];
            foreach ($facetConfig['@self'] as $field => $config) {
                $type = $config['type'] ?? 'terms';

                if ($type === 'terms') {
                    $facets['@self'][$field] = $this->metaDataFacetHandler->getTermsFacet(
                        field: $field,
                        baseQuery: $baseQuery
                    );
                } else if ($type === 'date_histogram') {
                    $interval = $config['interval'] ?? 'month';
                    $facets['@self'][$field] = $this->metaDataFacetHandler->getDateHistogramFacet(field: $field, interval: $interval, baseQuery: $baseQuery);
                } else if ($type === 'range') {
                    $ranges = $config['ranges'] ?? [];
                    $facets['@self'][$field] = $this->metaDataFacetHandler->getRangeFacet(field: $field, ranges: $ranges, baseQuery: $baseQuery);
                }
            }
        }

        // Process object field facets.
        $objectFacetConfig = array_filter(
            $facetConfig,
            function ($key) {
                return $key !== '@self';
            },
            ARRAY_FILTER_USE_KEY
        );

        foreach ($objectFacetConfig as $field => $config) {
            $type = $config['type'] ?? 'terms';

            if ($type === 'terms') {
                $facets[$field] = $this->mariaDbFacetHandler->getTermsFacet(
                    field: $field,
                    baseQuery: $baseQuery
                );
            } else if ($type === 'date_histogram') {
                $interval       = $config['interval'] ?? 'month';
                $facets[$field] = $this->mariaDbFacetHandler->getDateHistogramFacet(
                    field: $field,
                    interval: $interval,
                    baseQuery: $baseQuery
                );
            } else if ($type === 'range') {
                $ranges         = $config['ranges'] ?? [];
                $facets[$field] = $this->mariaDbFacetHandler->getRangeFacet(
                    field: $field,
                    ranges: $ranges,
                    baseQuery: $baseQuery
                );
            }
        }

        return $facets;

    }//end getSimpleFacets()

    /**
     * Get facetable fields from schemas.
     *
     * This method analyzes schema properties to determine which fields
     * are marked as facetable in the schema definitions. This is more
     * efficient than analyzing object data and provides consistent
     * faceting based on schema definitions.
     *
     * @param array $baseQuery Base query filters to apply for context.
     *
     * @throws \OCP\DB\Exception If a database error occurs.
     *
     * @return array[] Facetable fields with their configuration based on schema definitions.
     *
     * @psalm-return array<string, array>
     */
    public function getFacetableFieldsFromSchemas(array $baseQuery=[]): array
    {
        $facetableFields = [];

        // Get schemas to analyze based on query context.
        $schemas = $this->getSchemasForQuery($baseQuery);

        if (empty($schemas) === true) {
            return [];
        }

        // Process each schema's properties.
        foreach ($schemas as $schema) {
            $properties = $schema->getProperties();

            if (empty($properties) === true) {
                continue;
            }

            // Analyze each property for facetable configuration.
            foreach ($properties as $propertyKey => $property) {
                if ($this->isPropertyFacetable($property) === true) {
                    $fieldConfig = $this->generateFieldConfigFromProperty(
                        propertyKey: $propertyKey,
                        property: $property
                    );

                    if ($fieldConfig !== null) {
                        // If field already exists from another schema, merge configurations.
                        if (($facetableFields[$propertyKey] ?? null) !== null) {
                            $facetableFields[$propertyKey] = $this->mergeFieldConfigs(
                                existing: $facetableFields[$propertyKey],
                                new: $fieldConfig
                            );
                        } else {
                            $facetableFields[$propertyKey] = $fieldConfig;
                        }
                    }
                }
            }
        }//end foreach

        return $facetableFields;

    }//end getFacetableFieldsFromSchemas()

    /**
     * Get schemas for query context.
     *
     * Returns schemas that are relevant for the current query context.
     * If specific schemas are filtered in the query, only those are returned.
     * Otherwise, all schemas are returned.
     *
     * @param array $baseQuery Base query filters to apply.
     *
     * @throws \OCP\DB\Exception If a database error occurs.
     *
     * @return \OCA\OpenRegister\Db\Schema[] Array of Schema objects.
     *
     * @psalm-return array<\OCA\OpenRegister\Db\Schema>
     */
    private function getSchemasForQuery(array $baseQuery): array
    {
        $schemaFilters = [];

        // Check if specific schemas are requested in the query.
        if (($baseQuery['@self']['schema'] ?? null) !== null) {
            $schemaValue = $baseQuery['@self']['schema'];
            if (is_array($schemaValue) === true) {
                $schemaFilters = $schemaValue;
            } else {
                $schemaFilters = [$schemaValue];
            }
        }

        // Get schemas from the schema mapper.
        if (empty($schemaFilters) === true) {
            // Get all schemas.
            return $this->schemaMapper->findAll();
        } else {
            // Get specific schemas.
            return $this->schemaMapper->findMultiple($schemaFilters);
        }

    }//end getSchemasForQuery()

    /**
     * Check if a property is facetable.
     *
     * @param array $property The property definition.
     *
     * @return bool True if the property is facetable.
     */
    private function isPropertyFacetable(array $property): bool
    {
        return isset($property['facetable']) && $property['facetable'] === true;

    }//end isPropertyFacetable()

    /**
     * Generate field configuration from property definition.
     *
     * @param string $propertyKey The property key.
     * @param array  $property    The property definition.
     *
     * @return (array|mixed|string)[]|null Field configuration or null if not suitable for faceting.
     *
     * @psalm-return array{type: string, format: string, title: mixed|string, description: mixed|string, facet_types: array, source: 'schema', example?: mixed, cardinality?: string, minimum?: mixed, maximum?: mixed, intervals?: list{'day', 'week', 'month', 'year'}}|null
     */
    private function generateFieldConfigFromProperty(string $propertyKey, array $property): array|null
    {
        $type        = $property['type'] ?? 'string';
        $format      = $property['format'] ?? '';
        $title       = $property['title'] ?? $propertyKey;
        $description = $property['description'] ?? "Schema field: $propertyKey";
        $example     = $property['example'] ?? null;

        // Determine appropriate facet types based on property type and format.
        $facetTypes = $this->determineFacetTypesFromProperty(
            type: $type,
            format: $format
        );

        if (empty($facetTypes) === true) {
            return null;
        }

        $config = [
            'type'        => $type,
            'format'      => $format,
            'title'       => $title,
            'description' => $description,
            'facet_types' => $facetTypes,
            'source'      => 'schema',
        ];

        // Add example if available.
        if ($example !== null) {
            $config['example'] = $example;
        }

        // Add additional configuration based on type.
        switch ($type) {
            case 'string':
                if ($format === 'date' || $format === 'date-time') {
                    $config['intervals'] = ['day', 'week', 'month', 'year'];
                } else {
                    $config['cardinality'] = 'text';
                }
                break;

            case 'integer':
            case 'number':
                $config['cardinality'] = 'numeric';
                if (($property['minimum'] ?? null) !== null) {
                    $config['minimum'] = $property['minimum'];
                }

                if (($property['maximum'] ?? null) !== null) {
                    $config['maximum'] = $property['maximum'];
                }
                break;

            case 'boolean':
                $config['cardinality'] = 'binary';
                break;

            case 'array':
                $config['cardinality'] = 'array';
                break;
        }//end switch

        return $config;

    }//end generateFieldConfigFromProperty()

    /**
     * Determine facet types based on property type and format.
     *
     * @param string $type   The property type.
     * @param string $format The property format.
     *
     * @return string[] Array of suitable facet types.
     *
     * @psalm-return list{0: 'date_histogram'|'range'|'terms', 1?: 'range'|'terms'}
     */
    private function determineFacetTypesFromProperty(string $type, string $format): array
    {
        switch ($type) {
            case 'string':
                if ($format === 'date' || $format === 'date-time') {
                    return ['date_histogram', 'range'];
                } else if ($format === 'email' || $format === 'uri' || $format === 'uuid') {
                    return ['terms'];
                } else {
                    return ['terms'];
                }

            case 'integer':
            case 'number':
                return ['range', 'terms'];

            case 'boolean':
                return ['terms'];

            case 'array':
                return ['terms'];

            default:
                return ['terms'];
        }//end switch

    }//end determineFacetTypesFromProperty()

    /**
     * Merge field configurations from multiple schemas.
     *
     * @param array $existing The existing field configuration.
     * @param array $new      The new field configuration.
     *
     * @return (array|mixed)[] Merged field configuration.
     *
     * @psalm-return array{facet_types: array, title?: mixed, description?: mixed, example?: mixed,...}
     */
    private function mergeFieldConfigs(array $existing, array $new): array
    {
        // Merge facet types.
        $existingFacetTypes = $existing['facet_types'] ?? [];
        $newFacetTypes      = $new['facet_types'] ?? [];
        $merged = $existing;

        $merged['facet_types'] = array_unique(array_merge($existingFacetTypes, $newFacetTypes));

        // Use the more descriptive title and description if available.
        if (empty($existing['title']) === true && empty($new['title']) === false) {
            $merged['title'] = $new['title'];
        }

        if (empty($existing['description']) === true && empty($new['description']) === false) {
            $merged['description'] = $new['description'];
        }

        // Add example if not already present.
        if (($existing['example'] ?? null) === null && ($new['example'] ?? null) !== null) {
            $merged['example'] = $new['example'];
        }

        return $merged;

    }//end mergeFieldConfigs()
}//end class
