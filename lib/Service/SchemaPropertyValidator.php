<?php
/**
 * OpenRegister Schema Property Validator
 *
 * This file contains the class for validating schema properties
 * in the OpenRegister application.
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
use Psr\Log\LoggerInterface;

/**
 * Class SchemaPropertyValidator
 *
 * Service class for validating schema properties according to JSON Schema specification
 *
 * @package OCA\OpenRegister\Service
 */
class SchemaPropertyValidator {
    /**
     * @var LoggerInterface The logger instance
     */
    private LoggerInterface $logger;

    /**
     * @var array<string> List of valid JSON Schema types
     */
    private array $validTypes = [
        'string',
        'number',
        'integer',
        'boolean',
        'array',
        'object',
        'null'
    ];

    /**
     * @var array<string> List of valid string formats
     */
    private array $validStringFormats = [
        '',
        'date-time',
        'date',
        'time',
        'duration',
        'email',
        'idn-email',
        'hostname',
        'idn-hostname',
        'ipv4',
        'ipv6',
        'uri',
        'uri-reference',
        'iri',
        'iri-reference',
        'uuid',
        'uri-template',
        'json-pointer',
        'relative-json-pointer',
        'regex',
        'url', // Additional type
        'color', // Additional type
        'color-hex', // Additional type
        'color-hex-alpha', // Additional type
        'color-rgb', // Additional type
        'color-rgba', // Additional type
        'color-hsl', // Additional type
        'color-hsla', // Additional type
    ];

    /**
     * Constructor
     *
     * @param LoggerInterface $logger The logger instance
     */
    public function __construct(LoggerInterface $logger) {
        $this->logger = $logger;
    }

    /**
     * Validate a property definition against JSON Schema rules
     *
     * @param array  $property The property definition to validate
     * @param string $path     The current path in the schema (for error messages)
     *
     * @throws Exception If the property definition is invalid
     * @return bool True if the property is valid
     */
    public function validateProperty(array $property, string $path = ''): bool {
        // Type is required
        if (!isset($property['type'])) {
            throw new Exception("Property at '$path' must have a 'type' field");
        }

        // Validate type
        if (!in_array($property['type'], $this->validTypes)) {
            throw new Exception("Invalid type '{$property['type']}' at '$path'. Must be one of: " . implode(', ', $this->validTypes));
        }

        // Validate string format if present
        if ($property['type'] === 'string' && isset($property['format'])) {
            if (!in_array($property['format'], $this->validStringFormats)) {
                throw new Exception("Invalid string format '{$property['format']}' at '$path'. Must be one of: " . implode(', ', $this->validStringFormats));
            }
        }

        // Validate array items if type is array
        if ($property['type'] === 'array' && isset($property['items'])) {
            $this->validateProperty($property['items'], $path . '/items');
        }

        // Validate nested properties if type is object
        if ($property['type'] === 'object' && isset($property['properties'])) {
            $this->validateProperties($property['properties'], $path . '/properties');
        }

        // Validate minimum/maximum for numeric types
        if (in_array($property['type'], ['number', 'integer'])) {
            if (isset($property['minimum']) && !is_numeric($property['minimum'])) {
                throw new Exception("'minimum' at '$path' must be numeric");
            }
            if (isset($property['maximum']) && !is_numeric($property['maximum'])) {
                throw new Exception("'maximum' at '$path' must be numeric");
            }
            if (isset($property['minimum'], $property['maximum']) && $property['minimum'] > $property['maximum']) {
                throw new Exception("'minimum' cannot be greater than 'maximum' at '$path'");
            }
        }

        // Validate enum values if present
        if (isset($property['enum'])) {
            if (!is_array($property['enum']) || empty($property['enum'])) {
                throw new Exception("'enum' at '$path' must be a non-empty array");
            }
        }

        return true;
    }

    /**
     * Validate an entire properties object
     *
     * @param array  $properties The properties object to validate
     * @param string $path       The current path in the schema
     *
     * @throws Exception If any property definition is invalid
     * @return bool True if all properties are valid
     */
    public function validateProperties(array $properties, string $path = ''): bool {
        foreach ($properties as $propertyName => $property) {
            if (!is_array($property)) {
                throw new Exception("Property '$propertyName' at '$path' must be an object");
            }
            
            $this->validateProperty($property, $path . '/' . $propertyName);
        }
        
        return true;
    }

    /**
     * Get the list of valid types
     *
     * @return array<string> List of valid JSON Schema types
     */
    public function getValidTypes(): array {
        return $this->validTypes;
    }

    /**
     * Get the list of valid string formats
     *
     * @return array<string> List of valid string formats
     */
    public function getValidStringFormats(): array {
        return $this->validStringFormats;
    }
} 