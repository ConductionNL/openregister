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
use OCP\AppFramework\Db\Entity;
use OC\Files\Node\File;
use OCP\IUserSession;

/**
 * Entity class representing an object in the OpenRegister system
 *
 * This class handles storage and manipulation of objects including their metadata,
 * locking mechanisms, and serialization for API responses.
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
     * Text representation of the object.
     *
     * @var string|null Text representation of the object
     */
    protected ?string $textRepresentation = null;

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
     * @var DateTime|null Last update timestamp
     */
    protected ?DateTime $updated = null;

    /**
     * Creation timestamp.
     *
     * @var DateTime|null Creation timestamp
     */
    protected ?DateTime $created = null;

    /**
     * Published timestamp.
     *
     * @var DateTime|null Published timestamp
     */
    protected ?DateTime $published = null;

    /**
     * Published timestamp.
     *
     * @var DateTime|null Depublished timestamp
     */
    protected ?DateTime $depublished = null;


    /**
     * Initialize the entity and define field types
     */
    public function __construct()
    {
        $this->addType(fieldName:'uuid', type: 'string');
        $this->addType(fieldName:'uri', type: 'string');
        $this->addType(fieldName:'version', type: 'string');
        $this->addType(fieldName:'register', type: 'string');
        $this->addType(fieldName:'schema', type: 'string');
        $this->addType(fieldName:'object', type: 'json');
        $this->addType(fieldName:'files', type: 'json');
        $this->addType(fieldName:'relations', type: 'json');
        $this->addType(fieldName:'textRepresentation', type: 'text');
        $this->addType(fieldName:'locked', type: 'json');
        $this->addType(fieldName:'owner', type: 'string');
        $this->addType(fieldName:'authorization', type: 'json');
        $this->addType(fieldName:'folder', type: 'string');
        $this->addType(fieldName:'application', type: 'string');
        $this->addType(fieldName:'organisation', type: 'string');
        $this->addType(fieldName:'validation', type: 'json');
        $this->addType(fieldName:'deleted', type: 'json');
        $this->addType(fieldName:'geo', type: 'json');
        $this->addType(fieldName:'retention', type: 'json');
        $this->addType(fieldName:'size', type: 'string');
        $this->addType(fieldName:'schemaVersion', type: 'string');
        $this->addType(fieldName:'updated', type: 'datetime');
        $this->addType(fieldName:'created', type: 'datetime');
        $this->addType(fieldName:'published', type: 'datetime');
        $this->addType(fieldName:'depublished', type: 'datetime');
    }//end __construct()


    /**
     * Get the object data
     *
     * @return array The object data or empty array if null
     */
    public function getObject(): array
    {
        return ($this->object ?? []);

    }//end getObject()


    /**
     * Get the files data
     *
     * @return array The files data or empty array if null
     */
    public function getFiles(): array
    {
        return ($this->files ?? []);

    }//end getFiles()

    /**
     * Add a file's metadata to the object if its ID is not already present
     *
     * @param File $file The file to add
     * 
     * @return array The files data
     */
    public function addFile(File $file): array
    {
        $fileId = $file->getId();
        // Prevent duplicates by checking if a file with this ID already exists
        foreach ($this->files ?? [] as $f) {
            if (isset($f['id']) && $f['id'] === $fileId) {
                return $this->files; // Already present
            }
        }
        // Add file metadata (extend as needed)
        $this->files[] = [
            'id' => $fileId,
            'name' => $file->getName(),
            'size' => $file->getSize(),
            'mimetype' => $file->getMimetype(),
            'created' => method_exists($file, 'getUploadTime') ? $file->getUploadTime() : null,
            'etag' => method_exists($file, 'getEtag') ? $file->getEtag() : null,
        ];
        return $this->files;
    }

    /**
     * Get the relations data
     *
     * @return array The relations data or empty array if null
     */
    public function getRelations(): array
    {
        return ($this->relations ?? []);

    }//end getRelations()


    /**
     * Get the locked data
     *
     * @return array The locked data or empty array if null
     */
    public function getlocked(): ?array
    {
        return $this->locked;

    }//end getlocked()


    /**
     * Get the authorization data
     *
     * @return array The authorization data or empty array if null
     */
    public function getAuthorization(): ?array
    {
        return $this->authorization;

    }//end getAuthorization()


    /**
     * Get the deleted data
     *
     * @return array The deleted data or null if not deleted
     */
    public function getDeleted(): ?array
    {
        return $this->deleted;

    }//end getDeleted()


    /**
     * Get the deleted data
     *
     * @return array The deleted data or null if not deleted
     */
    public function getValidation(): ?array
    {
        return $this->validation;

    }//end getValidation()


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
     * Serialize the entity to JSON format
     *
     * Creates a metadata array containing object properties except sensitive fields.
     * Filters out 'object', 'textRepresentation' and 'authorization' fields and
     * stores remaining properties under '@self' key for API responses.
     *
     * @return array Serialized object data
     */
    public function jsonSerialize(): array
    {
        // Backwards compatibility for old objects.
        $object          = $this->object;
        $object['@self'] = $this->getObjectArray($object);
        $object['id']    = $this->getUuid();

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
        $objectArray = [
            'id'            => $this->uuid,
            'uri'           => $this->uri,
            'version'       => $this->version,
            'register'      => $this->register,
            'schema'        => $this->schema,
            'schemaVersion' => $this->schemaVersion,
            'files'         => $this->files,
            'relations'     => $this->relations,
            'locked'        => $this->locked,
            'owner'         => $this->owner,
            'folder'        => $this->folder,
            'application'   => $this->application,
            'organisation'  => $this->organisation,
            'validation'    => $this->validation,
            'geo'           => $this->geo,
            'retention'     => $this->retention,
            'size'          => $this->size,
            'updated'       => $this->getFormattedDate($this->updated),
            'created'       => $this->getFormattedDate($this->created),
            'published'     => $this->getFormattedDate($this->published),
            'depublished'    => $this->getFormattedDate($this->depublished),
            'deleted'       => $this->deleted,
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
            $lock = $this->locked;

            // If locked by different user.
            if ($lock['user'] !== $userId) {
                throw new Exception('Object is locked by another user');
            }

            // If same user, extend the lock.
            $expirationDate = new \DateTime($lock['expiration']);
            $newExpiration  = clone $now;
            $newExpiration->add(new \DateInterval('PT'.$duration.'S'));

            $this->locked = [
                'user'       => $userId,
                'process'    => ($process ?? $lock['process']),
                'created'    => $lock['created'],
                'duration'   => $duration,
                'expiration' => $newExpiration->format('c'),
            ];
        } else {
            // Create new lock.
            $expiration = clone $now;
            $expiration->add(new \DateInterval('PT'.$duration.'S'));

            $this->locked = [
                'user'       => $userId,
                'process'    => $process,
                'created'    => $now->format('c'),
                'duration'   => $duration,
                'expiration' => $expiration->format('c'),
            ];
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

        $this->locked = null;
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


}//end class
