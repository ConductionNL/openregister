<?php

/**
 * FacetsHandler
 *
 * Handler for facet operations on ObjectEntity.
 * Extracted from ObjectEntityMapper to follow Single Responsibility Principle.
 *
 * @category   Nextcloud
 * @package    OpenRegister
 * @author     Conduction BV <info@conduction.nl>
 * @license    EUPL-1.2 https://opensource.org/licenses/EUPL-1.2
 * @link       https://www.conduction.nl
 */

namespace OCA\OpenRegister\Db\ObjectEntity;

use OCA\OpenRegister\Db\FacetHandlers\MariaDbFacetHandler;
use OCA\OpenRegister\Db\FacetHandlers\MetaDataFacetHandler;
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
 * @category   Nextcloud
 * @package    OpenRegister
 * @author     Conduction BV <info@conduction.nl>
 * @license    EUPL-1.2 https://opensource.org/licenses/EUPL-1.2
 * @link       https://www.conduction.nl
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
     * @param LoggerInterface           $logger                Logger instance.
     * @param SchemaMapper              $schemaMapper          Schema mapper instance.
     * @param MetaDataFacetHandler|null $metaDataFacetHandler  Metadata facet handler (optional).
     * @param MariaDbFacetHandler|null  $mariaDbFacetHandler   MariaDB facet handler (optional).
     */
    public function __construct(
        LoggerInterface $logger,
        SchemaMapper $schemaMapper,
        ?MetaDataFacetHandler $metaDataFacetHandler = null,
        ?MariaDbFacetHandler $mariaDbFacetHandler = null
    ) {
        $this->logger = $logger;
        $this->schemaMapper = $schemaMapper;
        $this->metaDataFacetHandler = $metaDataFacetHandler;
        $this->mariaDbFacetHandler = $mariaDbFacetHandler;
    }

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
     * @return array Simple facet data using the new handlers.
     */
    public function getSimpleFacets(array $query = []): array
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
                    $facets['@self'][$field] = $this->metaDataFacetHandler->getTermsFacet($field, $baseQuery);
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
                $facets[$field] = $this->mariaDbFacetHandler->getTermsFacet($field, $baseQuery);
            } else if ($type === 'date_histogram') {
                $interval = $config['interval'] ?? 'month';
                $facets[$field] = $this->mariaDbFacetHandler->getDateHistogramFacet($field, $interval, $baseQuery);
            } else if ($type === 'range') {
                $ranges = $config['ranges'] ?? [];
                $facets[$field] = $this->mariaDbFacetHandler->getRangeFacet($field, $ranges, $baseQuery);
            }
        }

        return $facets;
    }

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
     * @return array Facetable fields with their configuration based on schema definitions.
     */
    public function getFacetableFieldsFromSchemas(array $baseQuery = []): array
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
                    $fieldConfig = $this->generateFieldConfigFromProperty($propertyKey, $property);

                    if ($fieldConfig !== null) {
                        // If field already exists from another schema, merge configurations.
                        if (($facetableFields[$propertyKey] ?? null) !== null) {
                            $facetableFields[$propertyKey] = $this->mergeFieldConfigs(
                                $facetableFields[$propertyKey],
                                $fieldConfig
                            );
                        } else {
                            $facetableFields[$propertyKey] = $fieldConfig;
                        }
                    }
                }
            }
        }

        return $facetableFields;
    }

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
     * @return array Array of Schema objects.
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
    }

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
    }

    /**
     * Generate field configuration from property definition.
     *
     * @param string $propertyKey The property key.
     * @param array  $property    The property definition.
     *
     * @return array|null Field configuration or null if not suitable for faceting.
     */
    private function generateFieldConfigFromProperty(string $propertyKey, array $property): array|null
    {
        $type = $property['type'] ?? 'string';
        $format = $property['format'] ?? '';
        $title = $property['title'] ?? $propertyKey;
        $description = $property['description'] ?? "Schema field: $propertyKey";
        $example = $property['example'] ?? null;

        // Determine appropriate facet types based on property type and format.
        $facetTypes = $this->determineFacetTypesFromProperty($type, $format);

        if (empty($facetTypes) === true) {
            return null;
        }

        $config = [
            'type' => $type,
            'format' => $format,
            'title' => $title,
            'description' => $description,
            'facet_types' => $facetTypes,
            'source' => 'schema',
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
        }

        return $config;
    }

    /**
     * Determine facet types based on property type and format.
     *
     * @param string $type   The property type.
     * @param string $format The property format.
     *
     * @return array Array of suitable facet types.
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
        }
    }

    /**
     * Merge field configurations from multiple schemas.
     *
     * @param array $existing The existing field configuration.
     * @param array $new      The new field configuration.
     *
     * @return array Merged field configuration.
     */
    private function mergeFieldConfigs(array $existing, array $new): array
    {
        // Merge facet types.
        $existingFacetTypes = $existing['facet_types'] ?? [];
        $newFacetTypes = $new['facet_types'] ?? [];
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
    }
}

