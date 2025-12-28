<?php

/**
 * OpenRegister Endpoint Entity
 *
 * This file contains the class for handling endpoint entity related operations
 * in the OpenRegister application.
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

namespace OCA\OpenRegister\Db;

use DateTime;
use DateInterval;
use stdClass;
use RuntimeException;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class Endpoint
 *
 * Represents an API endpoint configuration entity
 *
 * @package   OCA\OpenRegister\Db
 * @category  Database
 * @author    Conduction Development Team
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2
 * @version   GIT: <git_id>
 * @link      https://OpenRegister.app
 */
class Endpoint extends Entity implements JsonSerializable
{
    /**
     * Unique identifier for the endpoint.
     *
     * @var string|null Unique identifier for the endpoint
     */
    protected ?string $uuid = null;

    /**
     * Name of the endpoint.
     *
     * @var string|null The name of the endpoint
     */
    protected ?string $name = null;

    /**
     * Description of the endpoint.
     *
     * @var string|null The description of the endpoint
     */
    protected ?string $description = null;

    /**
     * Reference of the endpoint.
     *
     * @var string|null The reference of the endpoint
     */
    protected ?string $reference = null;

    /**
     * Version of the endpoint.
     *
     * @var string|null The version of the endpoint
     */
    protected ?string $version = '0.0.0';

    /**
     * The actual endpoint path e.g /api/buildings/{{id}}.
     * An endpoint may contain parameters e.g {{id}}.
     *
     * @var string|null The endpoint path
     */
    protected ?string $endpoint = null;

    /**
     * An array representation of the endpoint.
     * Automatically generated.
     *
     * @var array|null An array representation of the endpoint
     */
    protected ?array $endpointArray = [];

    /**
     * A regex representation of the endpoint.
     * Automatically generated.
     *
     * @var string|null A regex representation of the endpoint
     */
    protected ?string $endpointRegex = null;

    /**
     * HTTP method for the endpoint.
     * One of GET, POST, PUT, DELETE, PATCH, OPTIONS, HEAD.
     * Method and endpoint combination should be unique.
     *
     * @var string|null The HTTP method
     */
    protected ?string $method = null;

    /**
     * The target type to attach this endpoint to.
     * Should be one of: view, agent, webhook, register, schema.
     *
     * @var string|null The target type
     */
    protected ?string $targetType = null;

    /**
     * The target id to attach this endpoint to.
     *
     * @var string|null The target id
     */
    protected ?string $targetId = null;

    /**
     * Array of conditions to be applied.
     *
     * @var array|null Array of conditions
     */
    protected ?array $conditions = [];

    /**
     * Input mapping identifier.
     *
     * @var string|null The input mapping identifier
     */
    protected ?string $inputMapping = null;

    /**
     * Output mapping identifier.
     *
     * @var string|null The output mapping identifier
     */
    protected ?string $outputMapping = null;

    /**
     * Array of rules to be applied.
     *
     * @var array|null Array of rules
     */
    protected ?array $rules = [];

    /**
     * Array of configuration IDs that this endpoint belongs to.
     *
     * @var array|null Array of configuration IDs
     */
    protected ?array $configurations = [];

    /**
     * URL-friendly identifier for the endpoint.
     *
     * @var string|null URL-friendly slug for the endpoint
     */
    protected ?string $slug = null;

    /**
     * An array defining group-based permissions for CRUD actions.
     * The keys are the CRUD actions ('create', 'read', 'update', 'delete'),
     * and the values are arrays of group IDs that are permitted to perform that action.
     * If an action is not present as a key, or its value is an empty array,
     * it is assumed that all users have permission for that action.
     *
     * @var         array|null Array of group-based permissions
     * @phpstan-var array<string, array<string>>|null
     * @psalm-var   array<string, list<string>>|null
     */
    protected ?array $groups = [];

    /**
     * Organisation associated with the endpoint.
     *
     * @var string|null Organisation associated with the endpoint
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
        $this->addType(fieldName: 'name', type: 'string');
        $this->addType(fieldName: 'description', type: 'string');
        $this->addType(fieldName: 'reference', type: 'string');
        $this->addType(fieldName: 'version', type: 'string');
        $this->addType(fieldName: 'endpoint', type: 'string');
        $this->addType(fieldName: 'endpointArray', type: 'json');
        $this->addType(fieldName: 'endpointRegex', type: 'string');
        $this->addType(fieldName: 'method', type: 'string');
        $this->addType(fieldName: 'targetType', type: 'string');
        $this->addType(fieldName: 'targetId', type: 'string');
        $this->addType(fieldName: 'conditions', type: 'json');
        $this->addType(fieldName: 'inputMapping', type: 'string');
        $this->addType(fieldName: 'outputMapping', type: 'string');
        $this->addType(fieldName: 'rules', type: 'json');
        $this->addType(fieldName: 'configurations', type: 'json');
        $this->addType(fieldName: 'slug', type: 'string');
        $this->addType(fieldName: 'groups', type: 'json');
        $this->addType(fieldName: 'organisation', type: 'string');
        $this->addType(fieldName: 'created', type: 'datetime');
        $this->addType(fieldName: 'updated', type: 'datetime');
    }//end __construct()

    /**
     * Get the endpoint path
     *
     * @return string|null The endpoint path
     */
    public function getEndpoint(): ?string
    {
        return $this->endpoint;
    }//end getEndpoint()

    /**
     * Get the endpoint array representation
     *
     * @return array The endpoint array or empty array if null
     */
    public function getEndpointArray(): array
    {
        return $this->endpointArray ?? [];
    }//end getEndpointArray()

    /**
     * Get the conditions array
     *
     * @return array The conditions or empty array if null
     */
    public function getConditions(): array
    {
        return $this->conditions ?? [];
    }//end getConditions()

    /**
     * Get the rules array
     *
     * @return array The rules or empty array if null
     */
    public function getRules(): array
    {
        return $this->rules ?? [];
    }//end getRules()

    /**
     * Get the groups array
     *
     * @return string[][] The groups or empty array if null
     *
     * @psalm-return array<string, list<string>>
     */
    public function getGroups(): array
    {
        return $this->groups ?? [];
    }//end getGroups()

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
     * Get the slug for the endpoint.
     * If the slug is not set, generate one from the name.
     *
     * @phpstan-return non-empty-string
     *
     * @psalm-return non-empty-string
     */
    public function getSlug(): string
    {
        // Check if the slug is already set.
        if (empty($this->slug) === false) {
            return $this->slug;
        }

        // Generate a slug from the name if not set.
        // Convert the name to lowercase, replace spaces with hyphens, and remove non-alphanumeric characters.
        $generatedSlug = preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($this->name ?? '')));

        // Ensure the generated slug is not empty.
        if (empty($generatedSlug) === true) {
            throw new RuntimeException('Unable to generate a valid slug from the name.');
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

            $method = 'set' . ucfirst($key);

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
     * @return ((mixed|string[])[]|int|null|string)[]
     *
     * @phpstan-return array<string,mixed>
     *
     * @psalm-return array{id: int, uuid: null|string, name: null|string, description: null|string, reference: null|string, version: null|string, endpoint: null|string, endpointArray: array, endpointRegex: null|string, method: null|string, targetType: null|string, targetId: null|string, conditions: array, inputMapping: null|string, outputMapping: null|string, rules: array, configurations: array, slug: string, groups: array<string, list<string>>, organisation: null|string, created: null|string, updated: null|string}
     */
    public function jsonSerialize(): array
    {
        $result = [
            'id'             => $this->id,
            'uuid'           => $this->uuid,
            'name'           => $this->name,
            'description'    => $this->description,
            'reference'      => $this->reference,
            'version'        => $this->version,
            'endpoint'       => $this->endpoint,
            'endpointArray'  => $this->getEndpointArray(),
            'endpointRegex'  => $this->endpointRegex,
            'method'         => $this->method,
            'targetType'     => $this->targetType,
            'targetId'       => $this->targetId,
            'conditions'     => $this->getConditions(),
            'inputMapping'   => $this->inputMapping,
            'outputMapping'  => $this->outputMapping,
            'rules'          => $this->getRules(),
            'configurations' => $this->getConfigurations(),
            'slug'           => $this->getSlug(),
            'groups'         => $this->getGroups(),
            'organisation'   => $this->organisation,
        ];

        if (isset($this->created) === true) {
            $result['created'] = $this->created->format('c');
        } else {
            $result['created'] = null;
        }

        if (isset($this->updated) === true) {
            $result['updated'] = $this->updated->format('c');
        } else {
            $result['updated'] = null;
        }

        return $result;
    }//end jsonSerialize()
}//end class
