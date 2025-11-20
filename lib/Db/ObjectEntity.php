<?php
/**
 * OpenRegister Object Entity
 *
 * This file contains the class for handling object entity related operations
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
use Exception;
use JsonSerializable;
use OCA\OpenRegister\Service\FileService;
use OCP\AppFramework\Db\Entity;
use OC\Files\Node\File;
use OCP\IUserSession;

/**
 * Entity class representing an object in the OpenRegister system
 *
 * This class handles storage and manipulation of objects including their metadata,
 * locking mechanisms, and serialization for API responses.
 *
 * ⚠️  BULK OPERATIONS INTEGRATION:
 * When adding new database fields to this entity, consider whether they should be
 * excluded from bulk operation change detection in:
 * - OptimizedBulkOperations::buildMassiveInsertOnDuplicateKeyUpdateSQL()
 *
 * Database-managed fields (auto-populated/controlled by DB) should be added to the
 * $databaseManagedFields array to prevent false change detection:
 * - id, uuid: Primary identifiers (never change)
 * - created: Set by database DEFAULT CURRENT_TIMESTAMP
 * - updated: Set by database ON UPDATE CURRENT_TIMESTAMP
 * - published: Auto-managed by schema autoPublish logic
 *
 * User/application-managed fields that CAN trigger updates:
 * - name, description, summary, image: Extracted metadata
 * - object: The actual data payload
 * - register, schema: Context fields
 * - owner, organisation: Ownership fields
 *
 * Adding fields? Check if they should trigger change detection or be database-managed.
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getSlug()
 * @method void setSlug(?string $slug)
 * @method string|null getUri()
 * @method void setUri(?string $uri)
 * @method string|null getVersion()
 * @method void setVersion(?string $version)
 * @method string|null getRegister()
 * @method void setRegister(?string $register)
 * @method string|null getSchema()
 * @method void setSchema(?string $schema)
 * @method array|null getObject()
 * @method void setObject(?array $object)
 * @method array|null getFiles()
 * @method void setFiles(?array $files)
 * @method array|null getRelations()
 * @method void setRelations(?array $relations)
 * @method array|null getLocked()
 * @method void setLocked(?array $locked)
 * @method string|null getOwner()
 * @method void setOwner(?string $owner)
 * @method array|null getAuthorization()
 * @method void setAuthorization(?array $authorization)
 * @method string|null getFolder()
 * @method void setFolder(?string $folder)
 * @method string|null getApplication()
 * @method void setApplication(?string $application)
 * @method string|null getOrganisation()
 * @method void setOrganisation(?string $organisation)
 * @method array|null getValidation()
 * @method void setValidation(?array $validation)
 * @method array|null getDeleted()
 * @method void setDeleted(?array $deleted)
 * @method array|null getGeo()
 * @method void setGeo(?array $geo)
 * @method array|null getRetention()
 * @method void setRetention(?array $retention)
 * @method int|null getSize()
 * @method void setSize(?int $size)
 * @method string|null getName()
 * @method void setName(?string $name)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method string|null getSummary()
 * @method void setSummary(?string $summary)
 * @method string|null getImage()
 * @method void setImage(?string $image)
 * @method string|null getLabels()
 * @method void setLabels(?string $labels)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 * @method DateTime|null getUpdated()
 * @method void setUpdated(?DateTime $updated)
 * @method DateTime|null getPublished()
 * @method void setPublished(?DateTime $published)
 * @method DateTime|null getModified()
 * @method void setModified(?DateTime $modified)
 */
class ObjectEntity extends Entity implements JsonSerializable
{

    /**
     * Unique identifier for the object.
     *
     * @var string|null Unique identifier for the object
     */
    protected ?string $uuid = null;

    /**
     * URL-friendly identifier for the object.
     *
     * This field can be automatically populated via schema metadata mapping configuration.
     * Configure in schema: { "configuration": { "objectSlugField": "naam" } }
     * The field value will be converted to a URL-friendly slug format.
     *
     * @see SaveObject::hydrateObjectMetadata() for metadata mapping implementation
     * @var string|null URL-friendly slug for the object, unique within register+schema combination
     */
    protected ?string $slug = null;

    /**
     * URI of the object.
     *
     * @var string|null URI of the object
     */
    protected ?string $uri = null;

    /**
     * Version of the object.
     *
     * @var string|null Version of the object
     */
    protected ?string $version = null;

    /**
     * Register associated with the object.
     *
     * @var string|null Register associated with the object
     */
    protected ?string $register = null;

    /**
     * Schema associated with the object.
     *
     * @var string|null Schema associated with the object
     */
    protected ?string $schema = null;

    /**
     * Object data stored as an array.
     *
     * @var array|null Object data
     */
    protected ?array $object = [];

    /**
     * Files associated with the object.
     *
     * @var array|null Files associated with the object
     */
    protected ?array $files = [];

    /**
     * Relations to other objects stored as an array of file IDs.
     *
     * @var array|null Array of file IDs that are related to this object
     */
    protected ?array $relations = [];

    /**
     * Lock information for the object if locked.
     *
     * @var array|null Contains the locked object if the object is locked
     */
    protected ?array $locked = null;

    /**
     * The owner of this object.
     *
     * @var string|null The Nextcloud user that owns this object
     */
    protected ?string $owner = null;

    /**
     * Authorization details for the object.
     *
     * @var array|null JSON object describing authorizations
     */
    protected ?array $authorization = [];

    /**
     * Folder path where the object is stored.
     *
     * @var string|null The folder path where this object is stored
     */
    protected ?string $folder = null;

    /**
     * Application name associated with the object.
     *
     * @var string|null The application name
     */
    protected ?string $application = null;

    /**
     * Organisation name associated with the object.
     *
     * @var string|null The organisation name
     */
    protected ?string $organisation = null;

    /**
     * Validation results for the object.
     *
     * @var array|null Array describing validation results
     */
    protected ?array $validation = [];

    /**
     * Deletion details if the object is deleted.
     *
     * @var array|null Array describing deletion details
     */
    protected ?array $deleted = [];

    /**
     * Geographical details for the object.
     *
     * @var array|null Array describing geographical details
     */
    protected ?array $geo = [];

    /**
     * Retention details for the object.
     *
     * @var array|null Array describing retention details
     */
    protected ?array $retention = [];

    /**
     * Size of the object in byte.
     *
     * @var string|null Size of the object
     */
    protected ?string $size = null;

    /**
     * Version of the schema when this object was created
     *
     * @var string|null Version of the schema when this object was created
     */
    protected ?string $schemaVersion = null;

    /**
     * Last update timestamp.
     *
     * 🔒 DATABASE-MANAGED: Set by database ON UPDATE CURRENT_TIMESTAMP
     * This field should NOT be set during bulk preparation to avoid false change detection.
     *
     * @var DateTime|null Last update timestamp
     */
    protected ?DateTime $updated = null;

    /**
     * Creation timestamp.
     *
     * 🔒 DATABASE-MANAGED: Set by database DEFAULT CURRENT_TIMESTAMP
     * This field should NOT be set during bulk preparation to avoid false change detection.
     *
     * @var DateTime|null Creation timestamp
     */
    protected ?DateTime $created = null;

    /**
     * Published timestamp.
     *
     * This field can be automatically populated via schema metadata mapping configuration.
     * Configure in schema: { "configuration": { "objectPublishedField": "publicatieDatum" } }
     * Supports various datetime formats which will be parsed to DateTime objects.
     *
     * ⚠️  PARTIALLY DATABASE-MANAGED: Auto-publish logic sets this for NEW objects only.
     * Excluded from bulk change detection to avoid false updates on existing objects.
     *
     * @see SaveObject::hydrateObjectMetadata() for metadata mapping implementation
     * @var DateTime|null Published timestamp
     */
    protected ?DateTime $published = null;

    /**
     * Depublished timestamp.
     *
     * This field can be automatically populated via schema metadata mapping configuration.
     * Configure in schema: { "configuration": { "objectDepublishedField": "einddatum" } }
     * Supports various datetime formats which will be parsed to DateTime objects.
     *
     * @see SaveObject::hydrateObjectMetadata() for metadata mapping implementation
     * @var DateTime|null Depublished timestamp
     */
    protected ?DateTime $depublished = null;

    /**
     * Last log entry related to this object (not persisted, runtime only)
     *
     * @var         array|null
     * @phpstan-var array<string, mixed>|null
     * @psalm-var   array<string, mixed>|null
     */
    private ?array $lastLog = null;

    /**
     * Name of the object.
     *
     * This field is automatically populated via schema metadata mapping configuration.
     * Configure in schema: { "configuration": { "objectNameField": "naam" } } or
     * with twig-like concatenation: { "objectNameField": "{{ voornaam }} {{ achternaam }}" }
     *
     * @see SaveObject::hydrateObjectMetadata() for metadata mapping implementation
     * @var string|null Name of the object
     */
    protected ?string $name = null;

    /**
     * Description of the object.
     *
     * This field is automatically populated via schema metadata mapping configuration.
     * Configure in schema: { "configuration": { "objectDescriptionField": "beschrijving" } }
     * Supports dot notation for nested fields: "contact.beschrijving"
     *
     * @see SaveObject::hydrateObjectMetadata() for metadata mapping implementation
     * @var string|null Description of the object
     */
    protected ?string $description = null;

    /**
     * Summary of the object.
     *
     * This field is automatically populated via schema metadata mapping configuration.
     * Configure in schema: { "configuration": { "objectSummaryField": "beschrijvingKort" } }
     * Supports twig-like templates for combining fields.
     *
     * @see SaveObject::hydrateObjectMetadata() for metadata mapping implementation
     * @var string|null Summary of the object
     */
    protected ?string $summary = null;

    /**
     * Image of the object.
     *
     * This field is automatically populated via schema metadata mapping configuration.
     * Configure in schema: { "configuration": { "objectImageField": "afbeelding" } }
     * Can reference file fields or contain base64 encoded image data.
     *
     * @see SaveObject::hydrateObjectMetadata() for metadata mapping implementation
     * @var string|null Image of the object (base64 encoded or file reference)
     */
    protected ?string $image = null;

    /**
     * An array defining group-based permissions for CRUD actions.
     * The keys are the CRUD actions ('create', 'read', 'update', 'delete'),
     * and the values are arrays of group IDs that are permitted to perform that action.
     * If an action is not present as a key, or its value is an empty array,
     * it is assumed that all users have permission for that action.
     *
     * Example:
     * [
     *   'create' => ['group-admin', 'group-editors'],
     *   'read'   => ['group-viewers'],
     *   'update' => ['group-editors'],
     *   'delete' => ['group-admin']
     * ]
     *
     * @var         array|null
     * @phpstan-var array<string, array<string>>|null
     * @psalm-var   array<string, list<string>>|null
     */
    protected ?array $groups = [];

    /**
     * The expiration timestamp for this object
     *
     * @var DateTime|null The expiration timestamp for this object
     */
    protected ?DateTime $expires = null;


    /**
     * Initialize the entity and define field types
     */
    public function __construct()
    {
        $this->addType(fieldName: 'uuid', type: 'string');
        $this->addType(fieldName: 'slug', type: 'string');
        $this->addType(fieldName: 'uri', type: 'string');
        $this->addType(fieldName: 'version', type: 'string');
        $this->addType(fieldName: 'register', type: 'string');
        $this->addType(fieldName: 'schema', type: 'string');
        $this->addType(fieldName: 'object', type: 'json');
        $this->addType(fieldName: 'files', type: 'json');
        $this->addType(fieldName: 'relations', type: 'json');
        $this->addType(fieldName: 'locked', type: 'json');
        $this->addType(fieldName: 'owner', type: 'string');
        $this->addType(fieldName: 'authorization', type: 'json');
        $this->addType(fieldName: 'folder', type: 'string');
        $this->addType(fieldName: 'application', type: 'string');
        $this->addType(fieldName: 'organisation', type: 'string');
        $this->addType(fieldName: 'validation', type: 'json');
        $this->addType(fieldName: 'deleted', type: 'json');
        $this->addType(fieldName: 'geo', type: 'json');
        $this->addType(fieldName: 'retention', type: 'json');
        $this->addType(fieldName: 'size', type: 'string');
        $this->addType(fieldName: 'schemaVersion', type: 'string');
        $this->addType(fieldName: 'name', type: 'string');
        $this->addType(fieldName: 'description', type: 'string');
        $this->addType(fieldName: 'summary', type: 'string');
        $this->addType(fieldName: 'image', type: 'string');
        $this->addType(fieldName: 'updated', type: 'datetime');
        $this->addType(fieldName: 'created', type: 'datetime');
        $this->addType(fieldName: 'published', type: 'datetime');
        $this->addType(fieldName: 'depublished', type: 'datetime');
        $this->addType(fieldName: 'groups', type: 'json');
        $this->addType(fieldName: 'expires', type: 'datetime');

    }//end __construct()


    /**
     * Override getter to provide default empty arrays for JSON array fields
     *
     * We only override this one method from parent Entity - everything else
     * (setters, type conversion, change tracking) uses parent's implementation.
     *
     * The ONLY difference: we return [] instead of null for specific JSON fields
     * that represent collections, making code cleaner throughout the app.
     *
     * @param string $name The property name
     *
     * @return mixed The property value, or [] for unset array fields
     */
    protected function getter(string $name): mixed
    {
        // Array fields that should return [] instead of null when unset
        $arrayFieldsWithEmptyDefault = [
            'files',
            'relations',
            'authorization',
            'validation',
            'deleted',
            'groups',
            'geo',
            'retention',
        ];

        // If this is an array field and it's null, return empty array
        if (in_array($name, $arrayFieldsWithEmptyDefault) && property_exists($this, $name)) {
            return $this->$name ?? [];
        }

        // Otherwise, delegate to parent's standard getter behavior
        return parent::getter($name);

    }//end getter()


    /**
     * Get the object data and set the 'id' to the 'uuid'
     *
     * This getter has special logic to inject the UUID as 'id' field,
     * so it must remain explicit rather than using the magic method.
     *
     * @return array The object data with 'id' set to 'uuid', or empty array if null
     */
    public function getObject(): array
    {
        // Initialize the object data with an empty array if null
        $objectData = $this->object ?? [];

        // Ensure 'id' is the first field by setting it before merging with object data
        $objectData = array_merge(['id' => $this->uuid], $objectData);

        return $objectData;

    }//end getObject()


    /**
     * Get array of field names that are JSON type
     *
     * @return array List of field names that are JSON type
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
     * Hydrate the entity from an array of data
     *
     * @param array $object Array of data to hydrate the entity with
     *
     * @return self Returns the hydrated entity
     */
    public function hydrate(array $object): self
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
            } catch (Exception $exception) {
                // Silently ignore invalid properties.
            }
        }

        return $this;

    }//end hydrate()


    /**
     * Hydrate the entity from an serialized array of data
     *
     * @param array $object Array of data to hydrate the entity with
     *
     * @return self Returns the hydrated entity
     */
    public function hydrateObject(array $object): self
    {
        // Lets grap the metadata fields and remove them from the object
        $metaDataFields = $object['@self'];
        unset($object['@self']);

        // Hydrate the entity with the metadata fields
        $this->hydrate($metaDataFields);
        $this->setObject($object);

        // Return the hydrated entity
        return $this;

    }//end hydrateObject()


    /**
     * Serialize the entity to JSON format
     *
     * Merges the object's own data with a '@self' key containing metadata.
     * Ensures that if a name is not set, the UUID is used as a fallback.
     *
     * @return array Serialized object data
     */
    public function jsonSerialize(): array
    {
        // Backwards compatibility for old objects.
        $object = ($this->object ?? []);
        // Default to an empty array if $this->object is null.
        $object['@self'] = $this->getObjectArray($object);

        // Check if name is empty and set uuid as fallback
        if (empty($object['@self']['name'])) {
            $object['@self']['name'] = $this->uuid;
        }

        // Let's merge and return.
        return $object;

    }//end jsonSerialize()


    /**
     * Get array representation of all object properties
     *
     * @return array Array containing all object properties
     */
    public function getObjectArray(array $object=[]): array
    {
        // Initialize the object array with default properties.
        // Use getters to ensure our custom getter logic is applied (e.g., [] for null arrays)
        $objectArray = [
            'id'            => $this->uuid,
            'slug'          => $this->slug,
            'name'          => $this->name ?? $this->uuid,
            'description'   => $this->description ?? $this->id,
            'summary'       => $this->summary,
            'image'         => $this->image,
            'uri'           => $this->uri,
            'version'       => $this->version,
            'register'      => $this->register,
            'schema'        => $this->schema,
            'schemaVersion' => $this->schemaVersion,
            'files'         => $this->getFiles(),
            'relations'     => $this->getRelations(),
            'locked'        => $this->getLocked(),
            'owner'         => $this->owner,
            'organisation'  => $this->organisation,
            'groups'        => $this->getGroups(),
            'authorization' => $this->getAuthorization(),
            'folder'        => $this->folder,
            'application'   => $this->application,
            'validation'    => $this->getValidation(),
            'geo'           => $this->getGeo(),
            'retention'     => $this->getRetention(),
            'size'          => $this->size,
            'updated'       => $this->getFormattedDate($this->updated),
            'created'       => $this->getFormattedDate($this->created),
            'published'     => $this->getFormattedDate($this->published),
            'depublished'   => $this->getFormattedDate($this->depublished),
            'deleted'       => $this->getDeleted(),
        ];

        // Check for '@self' in the provided object array (this is the case if the object metadata is extended).
        if (isset($object['@self']) === true && is_array($object['@self']) === true) {
            $self = $object['@self'];

            // Use the '@self' values if they are arrays.
            if (isset($self['register']) === true && is_array($self['register']) === true) {
                $objectArray['register'] = $self['register'];
            }

            if (isset($self['schema']) === true && is_array($self['schema']) === true) {
                $objectArray['schema'] = $self['schema'];
            }

            if (isset($self['owner']) === true && is_array($self['owner']) === true) {
                $objectArray['owner'] = $self['owner'];
            }

            if (isset($self['organisation']) === true && is_array($self['organisation']) === true) {
                $objectArray['organisation'] = $self['organisation'];
            }

            if (isset($self['application']) === true && is_array($self['application']) === true) {
                $objectArray['application'] = $self['application'];
            }
        }//end if

        return $objectArray;

    }//end getObjectArray()


    /**
     * Format DateTime object to ISO 8601 string or return null
     *
     * @param DateTime|null $date The date to format
     *
     * @return string|null The formatted date or null
     */
    private function getFormattedDate(?DateTime $date): ?string
    {
        if ($date === null) {
            return null;
        }

        return $date->format('c');

    }//end getFormattedDate()


    /**
     * Lock the object for a specific duration
     *
     * @param IUserSession $userSession Current user session
     * @param string|null  $process     Optional process identifier
     * @param int|null     $duration    Lock duration in seconds (default: 1 hour)
     *
     * @throws Exception If object is already locked by another user
     *
     * @return bool True if lock was successful
     */
    public function lock(IUserSession $userSession, ?string $process=null, ?int $duration=3600): bool
    {
        $currentUser = $userSession->getUser();
        if ($currentUser === null) {
            throw new Exception('No user logged in');
        }

        $userId = $currentUser->getUID();
        $now    = new \DateTime();

        // If already locked, check if it's the same user and not expired.
        if ($this->isLocked() === true) {
            $lock = $this->setLocked();

            // If locked by different user.
            if ($lock['user'] !== $userId) {
                throw new Exception('Object is locked by another user');
            }

            // If same user, extend the lock.
            $expirationDate = new \DateTime($lock['expiration']);
            $newExpiration  = clone $now;
            $newExpiration->add(new \DateInterval('PT'.$duration.'S'));

            $this->setLocked(
                    [
                        'user'       => $userId,
                        'process'    => ($process ?? $lock['process']),
                        'created'    => $lock['created'],
                        'duration'   => $duration,
                        'expiration' => $newExpiration->format('c'),
                    ]
                    );
        } else {
            // Create new lock.
            $expiration = clone $now;
            $expiration->add(new \DateInterval('PT'.$duration.'S'));

            $this->setLocked(
                    [
                        'user'       => $userId,
                        'process'    => $process,
                        'created'    => $now->format('c'),
                        'duration'   => $duration,
                        'expiration' => $expiration->format('c'),
                    ]
                    );
        }//end if

        return true;

    }//end lock()


    /**
     * Unlock the object
     *
     * @param IUserSession $userSession Current user session
     *
     * @throws Exception If object is locked by another user
     *
     * @return bool True if unlock was successful
     */
    public function unlock(IUserSession $userSession): bool
    {
        if ($this->isLocked() === false) {
            return true;
        }

        $currentUser = $userSession->getUser();
        if ($currentUser === null) {
            throw new Exception('No user logged in');
        }

        $userId = $currentUser->getUID();

        // Check if locked by different user.
        if ($this->locked['user'] !== $userId) {
            throw new Exception('Object is locked by another user');
        }

        $this->setLocked(null);
        return true;

    }//end unlock()


    /**
     * Check if the object is currently locked
     *
     * @return bool True if object is locked and lock hasn't expired
     */
    public function isLocked(): bool
    {
        if ($this->locked === null) {
            return false;
        }

        // Check if lock has expired.
        $now        = new \DateTime();
        $expiration = new \DateTime($this->locked['expiration']);

        return $now < $expiration;

    }//end isLocked()


    /**
     * Get lock information
     *
     * @return array|null Lock information or null if not locked
     */
    public function getLockInfo(): ?array
    {
        if ($this->isLocked() === false) {
            return null;
        }

        return $this->locked;

    }//end getLockInfo()


    /**
     * Delete the object
     *
     * @param IUserSession $userSession     Current user session
     * @param string       $deletedReason   Reason for deletion
     * @param int          $retentionPeriod Retention period in days (default: 30 days)
     *
     * @throws Exception If no user is logged in
     *
     * @return self Returns the entity
     */
    public function delete(IUserSession $userSession, ?string $deletedReason=null, ?int $retentionPeriod=30): self
    {
        $currentUser = $userSession->getUser();
        if ($currentUser === null) {
            throw new Exception('No user logged in');
        }

        $userId    = $currentUser->getUID();
        $now       = new \DateTime();
        $purgeDate = clone $now;
        // $purgeDate->add(new \DateInterval('P'.(string)$retentionPeriod.'D')); @todo fix this
        $purgeDate->add(new \DateInterval('P31D'));

        $this->setDeleted(
                [
                    'deleted'         => $now->format('c'),
                    'deletedBy'       => $userId,
                    'deletedReason'   => $deletedReason,
                    'retentionPeriod' => $retentionPeriod,
                    'purgeDate'       => $purgeDate->format('c'),
                ]
                );

        return $this;

    }//end delete()


    /**
     * Get the last log entry for this object (runtime only)
     *
     * @return         array|null The last log entry or null if not set
     * @phpstan-return array<string, mixed>|null
     * @psalm-return   array<string, mixed>|null
     */
    public function getLastLog(): ?array
    {
        return $this->lastLog;

    }//end getLastLog()


    /**
     * Set the last log entry for this object (runtime only)
     *
     * @param         array|null $log The log entry to set
     * @phpstan-param array<string, mixed>|null $log
     * @psalm-param   array<string, mixed>|null $log
     *
     * @return void
     */
    public function setLastLog(?array $log=null): void
    {
        $this->lastLog = $log;

    }//end setLastLog()


    /**
     * String representation of the object entity
     *
     * This magic method is required for proper entity handling in Nextcloud
     * when the framework needs to convert the object to a string.
     *
     * @return string String representation of the object entity
     */
    public function __toString(): string
    {
        // Return the UUID if available, otherwise return a descriptive string
        if ($this->uuid !== null && $this->uuid !== '') {
            return $this->uuid;
        }

        // Fallback to ID if UUID is not available
        if ($this->id !== null) {
            return 'Object #'.$this->id;
        }

        // Final fallback
        return 'Object Entity';

    }//end __toString()


    /**
     * Check if this object is managed by any configuration
     *
     * This method checks if the object's ID is present in the objects array
     * of any provided configuration entities.
     *
     * @param array<Configuration> $configurations Array of Configuration entities to check against
     *
     * @return bool True if this object is managed by at least one configuration
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
            $objects = $configuration->getObjects();
            if (in_array($this->id, $objects, true) === true) {
                return true;
            }
        }

        return false;

    }//end isManagedByConfiguration()


    /**
     * Get the configuration that manages this object
     *
     * Returns the first configuration that has this object's ID in its objects array.
     * Returns null if the object is not managed by any configuration.
     *
     * @param array<Configuration> $configurations Array of Configuration entities to check against
     *
     * @return Configuration|null The configuration managing this object, or null
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
            $objects = $configuration->getObjects();
            if (in_array($this->id, $objects, true) === true) {
                return $configuration;
            }
        }

        return null;

    }//end getManagedByConfiguration()


}//end class
