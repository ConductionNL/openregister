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

namespace OCA\OpenRegister\Service;

use Exception;
use Psr\Log\LoggerInterface;

/**
 * Class SchemaPropertyValidatorService
 *
 * Service class for validating schema properties according to JSON Schema specification
 */
class SchemaPropertyValidatorService
{

    /**
     * Logger instance for logging operations
     *
     * @var LoggerInterface The logger instance
     */
    private LoggerInterface $logger;

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
    ];

    /**
     * Valid string formats for JSON Schema
     *
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
        // Additional type.
    ];


    /**
     * Constructor
     *
     * @param LoggerInterface $logger The logger instance
     */
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;

    }//end __construct()


    /**
     * Validate a property definition against JSON Schema rules
     *
     * @param array  $property The property definition to validate
     * @param string $path     The current path in the schema (for error messages)
     *
     * @throws Exception If the property definition is invalid
     * @return bool True if the property is valid
     */
    public function validateProperty(array $property, string $path=''): bool
    {
        // Type is required.
        if (isset($property['type']) === false) {
            throw new Exception("Property at '$path' must have a 'type' field");
        }

        // Validate type.
        if (in_array($property['type'], $this->validTypes) === false) {
            throw new Exception(
                "Invalid type '{$property['type']}' at '$path'. Must be one of: ".implode(', ', $this->validTypes)
            );
        }

        // Validate string format if present.
        if ($property['type'] === 'string' && isset($property['format']) === true) {
            if (in_array($property['format'], $this->validStringFormats) === false) {
                throw new Exception(
                    "Invalid string format '{$property['format']}' at '$path'. Must be one of: ".implode(', ', $this->validStringFormats)
                );
            }
        }

        // Validate array items if type is array.
        if ($property['type'] === 'array' && isset($property['items']) === true) {
            $this->validateProperty($property['items'], $path.'/items');
        }

        // Validate nested properties if type is object.
        if ($property['type'] === 'object' && isset($property['properties']) === true) {
            $this->validateProperties($property['properties'], $path.'/properties');
        }

        // Validate minimum/maximum for numeric types.
        if (in_array($property['type'], ['number', 'integer']) === true) {
            if (isset($property['minimum']) === true && is_numeric($property['minimum']) === false) {
                throw new Exception("'minimum' at '$path' must be numeric");
            }

            if (isset($property['maximum']) === true && is_numeric($property['maximum']) === false) {
                throw new Exception("'maximum' at '$path' must be numeric");
            }

            if (isset($property['minimum'], $property['maximum']) === true && $property['minimum'] > $property['maximum']) {
                throw new Exception("'minimum' cannot be greater than 'maximum' at '$path'");
            }
        }

        // Validate enum values if present.
        if (isset($property['enum']) === true) {
            if (is_array($property['enum']) === false || empty($property['enum']) === true) {
                throw new Exception("'enum' at '$path' must be a non-empty array");
            }
        }

        // Validate visible property if present
        if (isset($property['visible']) === true && is_bool($property['visible']) === false) {
            throw new Exception("'visible' at '$path' must be a boolean");
        }

        // Validate hideOnCollection property if present
        if (isset($property['hideOnCollection']) === true && is_bool($property['hideOnCollection']) === false) {
            throw new Exception("'hideOnCollection' at '$path' must be a boolean");
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
     * @return bool True if all properties are valid
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
     * Get the list of valid types
     *
     * @return array<string> List of valid JSON Schema types
     */
    public function getValidTypes(): array
    {
        return $this->validTypes;

    }//end getValidTypes()


    /**
     * Get the list of valid string formats
     *
     * @return array<string> List of valid string formats
     */
    public function getValidStringFormats(): array
    {
        return $this->validStringFormats;

    }//end getValidStringFormats()


}//end class
