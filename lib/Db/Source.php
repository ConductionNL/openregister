<?php
/**
 * OpenRegister Source
 *
 * This file contains the class for handling source related operations
 * in the OpenRegister application.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Source entity class
 *
 * Represents a source in the OpenRegister application
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getTitle()
 * @method void setTitle(?string $title)
 * @method string|null getVersion()
 * @method void setVersion(?string $version)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method string|null getDatabaseUrl()
 * @method void setDatabaseUrl(?string $databaseUrl)
 * @method string|null getType()
 * @method void setType(?string $type)
 * @method string|null getOrganisation()
 * @method void setOrganisation(?string $organisation)
 * @method DateTime|null getUpdated()
 * @method void setUpdated(?DateTime $updated)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 */
class Source extends Entity implements JsonSerializable
{

    /**
     * Unique identifier for the source
     *
     * @var string|null Unique identifier for the source
     */
    protected ?string $uuid = null;

    /**
     * Title of the source
     *
     * @var string|null Title of the source
     */
    protected ?string $title = null;

    /**
     * Version of the source
     *
     * @var string|null Version of the source
     */
    protected ?string $version = null;

    /**
     * Description of the source
     *
     * @var string|null Description of the source
     */
    protected ?string $description = null;

    /**
     * Database URL of the source
     *
     * @var string|null Database URL of the source
     */
    protected ?string $databaseUrl = null;

    /**
     * Type of the source
     *
     * @var string|null Type of the source
     */
    protected ?string $type = null;

    /**
     * Organisation UUID this source belongs to
     *
     * @var string|null Organisation UUID
     */
    protected ?string $organisation = null;

    /**
     * Configuration that manages this source (transient, not stored in DB)
     *
     * @var Configuration|null
     */
    private ?Configuration $managedByConfiguration = null;

    /**
     * Last update timestamp
     *
     * @var DateTime|null Last update timestamp
     */
    protected ?DateTime $updated = null;

    /**
     * Creation timestamp
     *
     * @var DateTime|null Creation timestamp
     */
    protected ?DateTime $created = null;


    /**
     * Constructor for the Source class
     *
     * Sets up field types for all properties
     */
    public function __construct()
    {
        $this->addType(fieldName: 'uuid', type: 'string');
        $this->addType(fieldName: 'title', type: 'string');
        $this->addType(fieldName: 'version', type: 'string');
        $this->addType(fieldName: 'description', type: 'string');
        $this->addType(fieldName: 'databaseUrl', type: 'string');
        $this->addType(fieldName: 'type', type: 'string');
        $this->addType(fieldName: 'organisation', type: 'string');
        $this->addType(fieldName: 'updated', type: 'datetime');
        $this->addType(fieldName: 'created', type: 'datetime');

    }//end __construct()


    /**
     * Get JSON fields from the entity
     *
     * Returns all fields that are of type 'json'
     *
     * @return string[] List of JSON field names
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
     * Hydrate the entity with data from an array
     *
     * Sets entity properties based on input array values
     *
     * @param array $object The data array to hydrate from
     *
     * @return static Returns $this for method chaining
     */
    public function hydrate(array $object): static
    {
        $jsonFields = $this->getJsonFields();

        if (isset($object['metadata']) === false) {
            $object['metadata'] = [];
        }

        foreach ($object as $key => $value) {
            if (in_array($key, $jsonFields) === true && $value === []) {
                $value = null;
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
     * Get the organisation UUID
     *
     * @return string|null The organisation UUID
     */
    public function getOrganisation(): ?string
    {
        return $this->organisation;

    }//end getOrganisation()


    /**
     * Set the organisation UUID
     *
     * @param string|null $organisation The organisation UUID
     *
     * @return void
     */
    public function setOrganisation(?string $organisation): void
    {
        $this->organisation = $organisation;
        $this->markFieldUpdated('organisation');

    }//end setOrganisation()


    /**
     * Convert entity to JSON serializable array
     *
     * Prepares the entity data for JSON serialization
     *
     * @return ((int|null|string)[]|int|null|string)[]
     *
     * @psalm-return array{id: int, uuid: null|string, title: null|string, version: null|string, description: null|string, databaseUrl: null|string, type: null|string, organisation: null|string, updated: null|string, created: null|string, managedByConfiguration: array{id: int, uuid: null|string, title: null|string}|null}
     */
    public function jsonSerialize(): array
    {
        $updated = null;
        if ($this->updated !== null) {
            $updated = $this->updated->format('c');
        }

        $created = null;
        if ($this->created !== null) {
            $created = $this->created->format('c');
        }

        return [
            'id'                     => $this->id,
            'uuid'                   => $this->uuid,
            'title'                  => $this->title,
            'version'                => $this->version,
            'description'            => $this->description,
            'databaseUrl'            => $this->databaseUrl,
            'type'                   => $this->type,
            'organisation'           => $this->organisation,
            'updated'                => $updated,
            'created'                => $created,
            'managedByConfiguration' => $this->getManagedByConfigurationData(),
        ];

    }//end jsonSerialize()


    /**
     * String representation of the source
     *
     * This magic method is required for proper entity handling in Nextcloud
     * when the framework needs to convert the object to a string.
     *
     * @return string String representation of the source
     */
    public function __toString(): string
    {
        // Return the title if available, otherwise return a descriptive string.
        if ($this->title !== null && $this->title !== '') {
            return $this->title;
        }

        // Fallback to UUID if available.
        if ($this->uuid !== null && $this->uuid !== '') {
            return $this->uuid;
        }

        // Fallback to ID if available.
        if ($this->id !== null) {
            return 'Source #'.$this->id;
        }

        // Final fallback.
        return 'Source';

    }//end __toString()


    /**
     * Get the configuration that manages this source (transient property)
     *
     * @return Configuration|null The managing configuration or null
     */
    public function getManagedByConfigurationEntity(): ?Configuration
    {
        return $this->managedByConfiguration;

    }//end getManagedByConfigurationEntity()


    /**
     * Set the configuration that manages this source (transient property)
     *
     * @param Configuration|null $configuration The managing configuration
     *
     * @return void
     */
    public function setManagedByConfigurationEntity(?Configuration $configuration): void
    {
        $this->managedByConfiguration = $configuration;

    }//end setManagedByConfigurationEntity()


    /**
     * Check if this source is managed by a configuration
     *
     * Returns true if this source's ID appears in any of the provided
     * configurations' sources arrays.
     *
     * @param array<Configuration> $configurations Array of Configuration entities to check against
     *
     * @return bool True if managed by a configuration, false otherwise
     *
     * @phpstan-param array<Configuration> $configurations
     * @psalm-param   array<Configuration> $configurations
     */
    public function isManagedByConfiguration(array $configurations): bool
    {
        if (empty($configurations) === true || $this->id === null) {
            return false;
        }

        foreach ($configurations as $configuration) {
            $sources = $configuration->getSources();
            if (in_array($this->id, $sources ?? [], true) === true) {
                return true;
            }
        }

        return false;

    }//end isManagedByConfiguration()


    /**
     * Get the configuration that manages this source
     *
     * Returns the first configuration that has this source's ID in its sources array.
     * Returns null if the source is not managed by any configuration.
     *
     * @param array<Configuration> $configurations Array of Configuration entities to check against
     *
     * @return Configuration|null The configuration managing this source, or null
     *
     * @phpstan-param array<Configuration> $configurations
     * @psalm-param   array<Configuration> $configurations
     */
    public function getManagedByConfiguration(array $configurations): ?Configuration
    {
        if (empty($configurations) === true || $this->id === null) {
            return null;
        }

        foreach ($configurations as $configuration) {
            $sources = $configuration->getSources();
            if (in_array($this->id, $sources ?? [], true) === true) {
                return $configuration;
            }
        }

        return null;

    }//end getManagedByConfiguration()


    /**
     * Get managed by configuration data as array or null
     *
     * @return (int|null|string)[]|null Configuration data or null
     *
     * @psalm-return array{id: int, uuid: null|string, title: null|string}|null
     */
    private function getManagedByConfigurationData(): array|null
    {
        if ($this->managedByConfiguration !== null) {
            return [
                'id'    => $this->managedByConfiguration->getId(),
                'uuid'  => $this->managedByConfiguration->getUuid(),
                'title' => $this->managedByConfiguration->getTitle(),
            ];
        }

        return null;

    }//end getManagedByConfigurationData()


}//end class
