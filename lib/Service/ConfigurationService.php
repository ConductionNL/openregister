<?php
/**
 * OpenRegister Configuration Service
 *
 * This file contains the service class for handling configuration imports and exports
 * in the OpenRegister application, supporting various formats including OpenAPI.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Ruben Linde <ruben@nextcloud.com>
 * @copyright Copyright (c) 2024, Ruben Linde (https://github.com/rubenlinde)
 * @license   AGPL-3.0
 * @version   1.0.0
 * @link      https://github.com/cloud-py-api/openregister
 */

namespace OCA\OpenRegister\Service;

use Exception;
use JsonException;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\ILogger;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Class ConfigurationService
 *
 * Service for importing and exporting configurations in various formats
 *
 * @package OCA\OpenRegister\Service
 */
class ConfigurationService {
    /**
     * @var SchemaMapper The schema mapper instance
     */
    private SchemaMapper $schemaMapper;

    /**
     * @var SchemaPropertyValidator The schema property validator instance
     */
    private SchemaPropertyValidator $validator;

    /**
     * @var LoggerInterface The logger instance
     */
    private LoggerInterface $logger;

    /**
     * Constructor
     *
     * @param SchemaMapper           $schemaMapper The schema mapper instance
     * @param SchemaPropertyValidator $validator    The schema property validator instance
     * @param LoggerInterface        $logger       The logger instance
     */
    public function __construct(
        SchemaMapper $schemaMapper,
        SchemaPropertyValidator $validator,
        LoggerInterface $logger
    ) {
        $this->schemaMapper = $schemaMapper;
        $this->validator = $validator;
        $this->logger = $logger;
    }

    /**
     * Import configuration from a JSON file
     *
     * @param string      $jsonContent The configuration JSON content
     * @param string      $format      The format of the configuration ('openapi', 'jsonschema', etc.)
     * @param string|null $owner       The owner of the schemas
     *
     * @throws JsonException If JSON parsing fails
     * @throws Exception If schema validation fails or format is unsupported
     * @return array<Schema> Array of created schemas
     */
    public function importFromJson(string $jsonContent, string $format, ?string $owner = null): array {
        try {
            $data = json_decode($jsonContent, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->logger->error('Failed to parse JSON: ' . $e->getMessage());
            throw new Exception('Invalid JSON format: ' . $e->getMessage());
        }

        return match ($format) {
            'openapi' => $this->importFromOpenApi($data, $owner),
            'jsonschema' => $this->importFromJsonSchema($data, $owner),
            default => throw new Exception('Unsupported configuration format: ' . $format),
        };
    }

    /**
     * Import configuration from OpenAPI format
     *
     * @param array       $data  The parsed OpenAPI data
     * @param string|null $owner The owner of the schemas
     *
     * @throws Exception If schema validation fails
     * @return array<Schema> Array of created schemas
     */
    private function importFromOpenApi(array $data, ?string $owner): array {
        if (!isset($data['components']['schemas'])) {
            throw new Exception('No schemas found in OpenAPI specification');
        }

        $createdSchemas = [];

        foreach ($data['components']['schemas'] as $schemaName => $schemaData) {
            $schema = [
                'uuid' => Uuid::v4(),
                'title' => $schemaName,
                'description' => $schemaData['description'] ?? '',
                'version' => '1.0.0',
                'properties' => $this->convertOpenApiProperties($schemaData['properties'] ?? []),
                'required' => $schemaData['required'] ?? [],
                'owner' => $owner,
                'hardValidation' => true,
                'configuration' => [
                    'source' => 'openapi',
                    'originalSchema' => $schemaData
                ]
            ];

            try {
                $createdSchema = $this->schemaMapper->createFromArray($schema);
                $createdSchemas[] = $createdSchema;
            } catch (Exception $e) {
                $this->logger->error('Failed to create schema ' . $schemaName . ': ' . $e->getMessage());
                throw new Exception('Failed to create schema ' . $schemaName . ': ' . $e->getMessage());
            }
        }

        return $createdSchemas;
    }

    /**
     * Import configuration from JSON Schema format
     *
     * @param array       $data  The parsed JSON Schema data
     * @param string|null $owner The owner of the schemas
     *
     * @throws Exception If schema validation fails
     * @return array<Schema> Array of created schemas
     */
    private function importFromJsonSchema(array $data, ?string $owner): array {
        if (!isset($data['$schema'])) {
            throw new Exception('Invalid JSON Schema: missing $schema property');
        }

        $schema = [
            'uuid' => Uuid::v4(),
            'title' => $data['title'] ?? 'Unnamed Schema',
            'description' => $data['description'] ?? '',
            'version' => '1.0.0',
            'properties' => $data['properties'] ?? [],
            'required' => $data['required'] ?? [],
            'owner' => $owner,
            'hardValidation' => true,
            'configuration' => [
                'source' => 'jsonschema',
                'originalSchema' => $data
            ]
        ];

        try {
            $createdSchema = $this->schemaMapper->createFromArray($schema);
            return [$createdSchema];
        } catch (Exception $e) {
            $this->logger->error('Failed to create schema: ' . $e->getMessage());
            throw new Exception('Failed to create schema: ' . $e->getMessage());
        }
    }

    /**
     * Export schema to a specific format
     *
     * @param Schema $schema The schema to export
     * @param string $format The format to export to ('openapi', 'jsonschema', etc.)
     *
     * @throws Exception If format is unsupported
     * @return array The exported configuration
     */
    public function exportSchema(Schema $schema, string $format): array {
        return match ($format) {
            'openapi' => $this->exportToOpenApi($schema),
            'jsonschema' => $this->exportToJsonSchema($schema),
            default => throw new Exception('Unsupported export format: ' . $format),
        };
    }

    /**
     * Export schema to OpenAPI format
     *
     * @param Schema $schema The schema to export
     *
     * @return array The OpenAPI specification
     */
    private function exportToOpenApi(Schema $schema): array {
        return [
            'openapi' => '3.0.0',
            'info' => [
                'title' => $schema->getTitle(),
                'description' => $schema->getDescription(),
                'version' => $schema->getVersion()
            ],
            'components' => [
                'schemas' => [
                    $schema->getTitle() => [
                        'type' => 'object',
                        'description' => $schema->getDescription(),
                        'properties' => $this->convertToOpenApiProperties($schema->getProperties()),
                        'required' => $schema->getRequired()
                    ]
                ]
            ]
        ];
    }

    /**
     * Export schema to JSON Schema format
     *
     * @param Schema $schema The schema to export
     *
     * @return array The JSON Schema
     */
    private function exportToJsonSchema(Schema $schema): array {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => $schema->getTitle(),
            'description' => $schema->getDescription(),
            'type' => 'object',
            'properties' => $schema->getProperties(),
            'required' => $schema->getRequired(),
            '$id' => $schema->getUuid(),
            'version' => $schema->getVersion()
        ];
    }

    /**
     * Convert OpenAPI properties to OpenRegister schema properties
     *
     * @param array $properties The OpenAPI properties
     *
     * @return array The converted properties
     */
    private function convertOpenApiProperties(array $properties): array {
        $converted = [];

        foreach ($properties as $propertyName => $property) {
            $converted[$propertyName] = [
                'title' => $propertyName,
                'description' => $property['description'] ?? '',
                'type' => $this->mapOpenApiType($property['type'] ?? 'string'),
                'format' => $property['format'] ?? null,
            ];

            // Handle enums
            if (isset($property['enum'])) {
                $converted[$propertyName]['enum'] = $property['enum'];
            }

            // Handle nested objects
            if ($property['type'] === 'object' && isset($property['properties'])) {
                $converted[$propertyName]['properties'] = $this->convertOpenApiProperties($property['properties']);
            }

            // Handle arrays
            if ($property['type'] === 'array' && isset($property['items'])) {
                $converted[$propertyName]['items'] = [
                    'type' => $this->mapOpenApiType($property['items']['type'] ?? 'string'),
                ];
                if (isset($property['items']['format'])) {
                    $converted[$propertyName]['items']['format'] = $property['items']['format'];
                }
            }

            // Handle validation constraints
            if (isset($property['minimum'])) {
                $converted[$propertyName]['minimum'] = $property['minimum'];
            }
            if (isset($property['maximum'])) {
                $converted[$propertyName]['maximum'] = $property['maximum'];
            }
            if (isset($property['pattern'])) {
                $converted[$propertyName]['pattern'] = $property['pattern'];
            }
        }

        return $converted;
    }

    /**
     * Convert OpenRegister schema properties to OpenAPI properties
     *
     * @param array $properties The OpenRegister schema properties
     *
     * @return array The OpenAPI properties
     */
    private function convertToOpenApiProperties(array $properties): array {
        $converted = [];

        foreach ($properties as $propertyName => $property) {
            $converted[$propertyName] = [
                'type' => $property['type'],
                'description' => $property['description'] ?? ''
            ];

            if (isset($property['format'])) {
                $converted[$propertyName]['format'] = $property['format'];
            }

            if (isset($property['enum'])) {
                $converted[$propertyName]['enum'] = $property['enum'];
            }

            if ($property['type'] === 'object' && isset($property['properties'])) {
                $converted[$propertyName]['properties'] = $this->convertToOpenApiProperties($property['properties']);
                if (isset($property['required'])) {
                    $converted[$propertyName]['required'] = $property['required'];
                }
            }

            if ($property['type'] === 'array' && isset($property['items'])) {
                $converted[$propertyName]['items'] = [
                    'type' => $property['items']['type']
                ];
                if (isset($property['items']['format'])) {
                    $converted[$propertyName]['items']['format'] = $property['items']['format'];
                }
            }

            // Convert validation constraints
            if (isset($property['minimum'])) {
                $converted[$propertyName]['minimum'] = $property['minimum'];
            }
            if (isset($property['maximum'])) {
                $converted[$propertyName]['maximum'] = $property['maximum'];
            }
            if (isset($property['pattern'])) {
                $converted[$propertyName]['pattern'] = $property['pattern'];
            }
        }

        return $converted;
    }

    /**
     * Map OpenAPI types to JSON Schema types
     *
     * @param string $type The OpenAPI type
     *
     * @return string The JSON Schema type
     */
    private function mapOpenApiType(string $type): string {
        $typeMap = [
            'integer' => 'integer',
            'number' => 'number',
            'string' => 'string',
            'boolean' => 'boolean',
            'array' => 'array',
            'object' => 'object'
        ];

        return $typeMap[$type] ?? 'string';
    }
} 