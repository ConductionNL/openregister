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
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Service\Schemas;

use Exception;
use Psr\Log\LoggerInterface;

/**
 * Class PropertyValidatorHandler
 *
 * Service class for validating schema properties according to JSON Schema specification
 */
class PropertyValidatorHandler
{

    /**
     * Valid JSON Schema types
     *
     * @var array<string> List of valid JSON Schema types
     */
    private array $validTypes = [
        'string',
        'number',
        'integer',
        'boolean',
        'array',
        'object',
        'null',
        'file',
    ];

    /**
     * Valid string formats for JSON Schema
     *
     * @var array<string> List of valid string formats
     */
    private array $validStringFormats = [
        '',
        // Text content formats.
        'text',
        'markdown',
        'html',
        // Standard JSON Schema formats.
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
        'url',
        // Additional type.
        'color',
        // Additional type.
        'color-hex',
        // Additional type.
        'color-hex-alpha',
        // Additional type.
        'color-rgb',
        // Additional type.
        'color-rgba',
        // Additional type.
        'color-hsl',
        // Additional type.
        'color-hsla',
        // Semantic versioning format.
        'semver',
    ];


    /**
     * Validate a property definition against JSON Schema rules
     *
     * @param array  $property The property definition to validate
     * @param string $path     The current path in the schema (for error messages)
     *
     * @throws Exception If the property definition is invalid
     *
     * @return true True if the property is valid
     *
     * @psalm-suppress PossiblyUnusedReturnValue
     */
    public function validateProperty(array $property, string $path=''): bool
    {
        // If property has oneOf, treat the contents as separate properties and return the result of those checks.
        if (($property['oneOf'] ?? null) !== null) {
            return $this->validateProperties($property['oneOf'], $path.'/oneOf');
        }

        // Type is required.
        if (isset($property['type']) === FALSE) {
            throw new Exception("Property at '$path' must have a 'type' field");
        }

        // Validate type.
        if (in_array($property['type'], $this->validTypes) === false) {
            throw new Exception(
                "Invalid type '{$property['type']}' at '$path'. Must be one of: ".implode(', ', $this->validTypes)
            );
        }

        // Validate string format if present.
        if ($property['type'] === 'string' && (($property['format'] ?? null) !== null)) {
            if (in_array($property['format'], $this->validStringFormats) === false) {
                throw new Exception(
                    "Invalid string format '{$property['format']}' at '$path'. Must be one of: ".implode(', ', $this->validStringFormats)
                );
            }
        }

        // Validate array items if type is array.
        if ($property['type'] === 'array' && (($property['items'] ?? null) !== null) && isset($property['items']['$ref']) === FALSE) {
            $this->validateProperty($property['items'], $path.'/items');
        }

        // Validate nested properties if type is object.
        if ($property['type'] === 'object' && (($property['properties'] ?? null) !== null)) {
            $this->validateProperties($property['properties'], $path.'/properties');
        }

        // Validate minimum/maximum for numeric types.
        if (in_array($property['type'], ['number', 'integer'], true) === true) {
            if (($property['minimum'] ?? null) !== null && is_numeric($property['minimum']) === false) {
                throw new Exception("'minimum' at '$path' must be numeric");
            }

            if (($property['maximum'] ?? null) !== null && is_numeric($property['maximum']) === false) {
                throw new Exception("'maximum' at '$path' must be numeric");
            }

            if (($property['minimum'] ?? null) !== null && ($property['maximum'] ?? null) !== null && ($property['minimum'] > $property['maximum']) === true) {
                throw new Exception("'minimum' cannot be greater than 'maximum' at '$path'");
            }
        }

        // Validate file properties if type is file.
        if ($property['type'] === 'file') {
            $this->validateFileProperty($property, $path);
        }

        // Validate enum values if present.
        if (($property['enum'] ?? null) !== null) {
            if (is_array($property['enum']) === false || empty($property['enum']) === true) {
                throw new Exception("'enum' at '$path' must be a non-empty array");
            }
        }

        // Validate visible property if present.
        if (($property['visible'] ?? null) !== null && is_bool($property['visible']) === false) {
            throw new Exception("'visible' at '$path' must be a boolean");
        }

        // Validate hideOnCollection property if present.
        if (($property['hideOnCollection'] ?? null) !== null && is_bool($property['hideOnCollection']) === false) {
            throw new Exception("'hideOnCollection' at '$path' must be a boolean");
        }

        // Validate hideOnForm property if present.
        if (($property['hideOnForm'] ?? null) !== null && is_bool($property['hideOnForm']) === false) {
            throw new Exception("'hideOnForm' at '$path' must be a boolean");
        }

        return true;

    }//end validateProperty()


    /**
     * Validate an entire properties object
     *
     * @param array  $properties The properties object to validate
     * @param string $path       The current path in the schema
     *
     * @throws Exception If any property definition is invalid
     *
     * @return true True if all properties are valid
     */
    public function validateProperties(array $properties, string $path=''): bool
    {
        foreach ($properties as $propertyName => $property) {
            if (is_array($property) === false) {
                throw new Exception("Property '$propertyName' at '$path' must be an object");
            }

            $this->validateProperty($property, $path.'/'.$propertyName);
        }

        return true;

    }//end validateProperties()


    /**
     * Validate file-specific properties
     *
     * Validates file property configuration options including allowedTypes,
     * maxSize, allowedTags, and autoTags
     *
     * @param array  $property The file property definition to validate
     * @param string $path     The current path in the schema (for error messages)
     *
     * @throws Exception If the file property configuration is invalid
     *
     * @return true
     *
     * @psalm-param array<string, mixed> $property
     *
     * @phpstan-param array<string, mixed> $property
     *
     * @psalm-return   bool
     * @phpstan-return bool
     *
     * @psalm-suppress UnusedReturnValue
     */
    private function validateFileProperty(array $property, string $path): bool
    {
        // Validate allowedTypes if present.
        if (($property['allowedTypes'] ?? null) !== null) {
            if (is_array($property['allowedTypes']) === false) {
                throw new Exception("'allowedTypes' at '$path' must be an array");
            }

            // Validate each MIME type.
            foreach ($property['allowedTypes'] as $index => $mimeType) {
                if (is_string($mimeType) === false) {
                    throw new Exception("'allowedTypes[$index]' at '$path' must be a string");
                }

                // Basic MIME type validation (type/subtype).
                if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9!#$&\-\^_]*\/[a-zA-Z0-9][a-zA-Z0-9!#$&\-\^_.]*$/', $mimeType) === 0) {
                    throw new Exception("'allowedTypes[$index]' at '$path' contains invalid MIME type format: '$mimeType'");
                }
            }
        }

        // Validate maxSize if present.
        if (($property['maxSize'] ?? null) !== null) {
            if (is_int($property['maxSize']) === false && is_numeric($property['maxSize']) === false) {
                throw new Exception("'maxSize' at '$path' must be a numeric value");
            }

            $maxSize = (int) $property['maxSize'];
            if ($maxSize < 0) {
                throw new Exception("'maxSize' at '$path' must be a positive number");
            }

            // Reasonable upper limit (100MB).
            if ($maxSize > 104857600) {
                throw new Exception("'maxSize' at '$path' exceeds maximum allowed size (100MB)");
            }
        }

        // Validate allowedTags if present.
        if (($property['allowedTags'] ?? null) !== null) {
            if (is_array($property['allowedTags']) === false) {
                throw new Exception("'allowedTags' at '$path' must be an array");
            }

            foreach ($property['allowedTags'] as $index => $tag) {
                if (is_string($tag) === false) {
                    throw new Exception("'allowedTags[$index]' at '$path' must be a string");
                }

                // Basic tag validation (no empty strings, reasonable length).
                if (trim($tag) === '') {
                    throw new Exception("'allowedTags[$index]' at '$path' cannot be empty");
                }

                if (strlen($tag) > 50) {
                    throw new Exception("'allowedTags[$index]' at '$path' exceeds maximum length (50 characters)");
                }
            }
        }

        // Validate autoTags if present.
        if (($property['autoTags'] ?? null) !== null) {
            if (is_array($property['autoTags']) === false) {
                throw new Exception("'autoTags' at '$path' must be an array");
            }

            foreach ($property['autoTags'] as $index => $tag) {
                if (is_string($tag) === false) {
                    throw new Exception("'autoTags[$index]' at '$path' must be a string");
                }

                // Basic tag validation (no empty strings, reasonable length).
                if (trim($tag) === '') {
                    throw new Exception("'autoTags[$index]' at '$path' cannot be empty");
                }

                if (strlen($tag) > 50) {
                    throw new Exception("'autoTags[$index]' at '$path' exceeds maximum length (50 characters)");
                }
            }
        }

        return true;

    }//end validateFileProperty()


}//end class
