<?php

/**
 * OpenRegister Mapping Entity
 *
 * This file contains the class for handling mapping entity related operations
 * in the OpenRegister application. Mappings define how to transform data between
 * different formats using Twig templating.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class Mapping
 *
 * Represents a mapping configuration entity that defines how to transform data between different formats.
 *
 * @package   OCA\OpenRegister\Db
 * @category  Database
 * @author    Conduction Development Team
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2
 * @version   GIT: <git_id>
 * @link      https://OpenRegister.app
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.StaticAccess)         Transliterator::create is the correct pattern for ICU transliteration
 * @SuppressWarnings(PHPMD.ElseExpression)       Else improves readability in getSlug method
 * @SuppressWarnings(PHPMD.ErrorControlOperator) @ suppression needed for Transliterator which may not be available
 */
class Mapping extends Entity implements JsonSerializable
{

    /**
     * Unique identifier for the mapping.
     *
     * @var string|null Unique identifier for the mapping
     */
    protected ?string $uuid = null;

    /**
     * External reference for the mapping.
     *
     * @var string|null External reference
     */
    protected ?string $reference = null;

    /**
     * Version of the mapping.
     *
     * @var string|null The version of the mapping (format: X.Y.Z)
     */
    protected ?string $version = '0.0.0';

    /**
     * Name of the mapping.
     *
     * @var string|null The name of the mapping
     */
    protected ?string $name = null;

    /**
     * Description of the mapping.
     *
     * @var string|null The description of the mapping
     */
    protected ?string $description = null;

    /**
     * The core mapping configuration.
     * Defines how to transform data using Twig templating.
     *
     * @var array|null The mapping configuration
     */
    protected ?array $mapping = [];

    /**
     * Array of keys to remove from output.
     *
     * @var array|null Array of keys to unset
     */
    protected ?array $unset = [];

    /**
     * Type casting rules for specific fields.
     *
     * @var array|null Array of cast rules
     */
    protected ?array $cast = [];

    /**
     * Whether to include input data not explicitly mapped.
     *
     * @var boolean|null Pass through flag
     */
    protected ?bool $passThrough = null;

    /**
     * Array of configuration IDs that this mapping belongs to.
     *
     * @var array|null Array of configuration IDs
     */
    protected ?array $configurations = [];

    /**
     * URL-friendly identifier for the mapping.
     *
     * @var string|null URL-friendly slug for the mapping
     */
    protected ?string $slug = null;

    /**
     * Organisation associated with the mapping.
     *
     * @var string|null Organisation associated with the mapping
     */
    protected ?string $organisation = null;

    /**
     * Creation timestamp.
     *
     * @var DateTime|null Creation timestamp
     */
    protected ?DateTime $created = null;

    /**
     * Last update timestamp.
     *
     * @var DateTime|null Last update timestamp
     */
    protected ?DateTime $updated = null;

    /**
     * Initialize the entity and define field types
     */
    public function __construct()
    {
        $this->addType(fieldName: 'uuid', type: 'string');
        $this->addType(fieldName: 'reference', type: 'string');
        $this->addType(fieldName: 'version', type: 'string');
        $this->addType(fieldName: 'name', type: 'string');
        $this->addType(fieldName: 'description', type: 'string');
        $this->addType(fieldName: 'mapping', type: 'json');
        $this->addType(fieldName: 'unset', type: 'json');
        $this->addType(fieldName: 'cast', type: 'json');
        $this->addType(fieldName: 'passThrough', type: 'boolean');
        $this->addType(fieldName: 'configurations', type: 'json');
        $this->addType(fieldName: 'slug', type: 'string');
        $this->addType(fieldName: 'organisation', type: 'string');
        $this->addType(fieldName: 'created', type: 'datetime');
        $this->addType(fieldName: 'updated', type: 'datetime');
    }//end __construct()

    /**
     * Get the mapping configuration
     *
     * @return array The mapping configuration or empty array if null
     */
    public function getMapping(): array
    {
        return $this->mapping ?? [];
    }//end getMapping()

    /**
     * Get the unset configuration
     *
     * @return array The unset configuration or empty array if null
     */
    public function getUnset(): array
    {
        return $this->unset ?? [];
    }//end getUnset()

    /**
     * Get the cast configuration
     *
     * @return array The cast configuration or empty array if null
     */
    public function getCast(): array
    {
        return $this->cast ?? [];
    }//end getCast()

    /**
     * Get the configurations array
     *
     * @return array The configurations or empty array if null
     */
    public function getConfigurations(): array
    {
        return $this->configurations ?? [];
    }//end getConfigurations()

    /**
     * Get array of field names that are JSON type
     *
     * @return string[] List of field names that are JSON type
     *
     * @psalm-return list<string>
     */
    public function getJsonFields(): array
    {
        return array_keys(
            array_filter(
                $this->getFieldTypes(),
                function ($field) {
                    return $field === 'json';
                }
            )
        );
    }//end getJsonFields()

    /**
     * Get the slug for the mapping.
     * If the slug is not set, generate one from the name.
     *
     * @return string The mapping slug.
     *
     * @phpstan-return non-empty-string
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     *
     * @psalm-suppress UndefinedClass Transliterator is optional PHP intl extension
     */
    public function getSlug(): string
    {
        // Return existing slug when present.
        if (empty($this->slug) === false) {
            return $this->slug;
        }

        // Prepare name.
        $name = trim((string) ($this->name ?? ''));

        // Attempt transliteration to ASCII for non-Latin names.
        $transliterated = $name;
        if ($name !== '') {
            if (class_exists('\Transliterator') === true) {
                $transliterator = \Transliterator::create('Any-Latin; Latin-ASCII');
                if ($transliterator !== null) {
                    $transliterated = (string) $transliterator->transliterate($name);
                }
            } else if (function_exists('iconv') === true) {
                $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
                if ($converted !== false) {
                    $transliterated = $converted;
                }
            }
        }//end if

        // Convert to slug: lowercase, non-alphanumeric to hyphens, trim.
        $generatedSlug = strtolower($transliterated);
        $generatedSlug = preg_replace('/[^a-z0-9]+/', '-', $generatedSlug ?? '');
        $generatedSlug = trim((string) $generatedSlug, '-');

        // Safe fallback if empty.
        if ($generatedSlug === '') {
            $prefix = 'mapping';
            if (isset($this->id) === true && (string) $this->id !== '') {
                $generatedSlug = $prefix.'-'.(string) $this->id;
            } else {
                try {
                    $generatedSlug = $prefix.'-'.bin2hex(random_bytes(4));
                } catch (\Exception $e) {
                    $generatedSlug = $prefix.'-'.substr(md5((string) $name), 0, 8);
                }
            }
        }

        return $generatedSlug;
    }//end getSlug()

    /**
     * Hydrate the entity from an array of data
     *
     * @param array $object Array of data to hydrate the entity with
     *
     * @return static Returns the hydrated entity
     */
    public function hydrate(array $object): static
    {
        $jsonFields = $this->getJsonFields();

        foreach ($object as $key => $value) {
            if (in_array($key, $jsonFields) === true && $value === []) {
                $value = [];
            }

            $method = 'set'.ucfirst($key);

            try {
                $this->$method($value);
            } catch (\Exception $exception) {
                // Silently ignore invalid properties.
            }
        }

        return $this;
    }//end hydrate()

    /**
     * Serialize the entity to JSON format
     *
     * @return array<string, mixed>
     *
     * @phpstan-return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        $result = [
            'id'             => $this->id,
            'uuid'           => $this->uuid,
            'name'           => $this->name,
            'description'    => $this->description,
            'version'        => $this->version,
            'reference'      => $this->reference,
            'mapping'        => $this->getMapping(),
            'unset'          => $this->getUnset(),
            'cast'           => $this->getCast(),
            'passThrough'    => $this->passThrough,
            'configurations' => $this->getConfigurations(),
            'slug'           => $this->getSlug(),
            'organisation'   => $this->organisation,
        ];

        $result['created'] = null;
        if (isset($this->created) === true) {
            $result['created'] = $this->created->format('c');
        }

        $result['updated'] = null;
        if (isset($this->updated) === true) {
            $result['updated'] = $this->updated->format('c');
        }

        return $result;
    }//end jsonSerialize()
}//end class
