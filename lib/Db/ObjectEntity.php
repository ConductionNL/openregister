<?php

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;
use OCP\IUserSession;

/**
 * Entity class representing an object in the OpenRegister system
 * 
 * This class handles storage and manipulation of objects including their metadata,
 * locking mechanisms, and serialization for API responses.
 */
class ObjectEntity extends Entity implements JsonSerializable
{
	protected ?string $uuid = null;
	protected ?string $uri = null;
	protected ?string $version = null;
	protected ?string $register = null;
	protected ?string $schema = null;
	protected ?array $object = [];
	protected ?array $files = []; // array of file ids that are related to this object
	protected ?array $relations = []; // array of object ids that this object is related to
	protected ?string $textRepresentation = null;
	protected ?array $locked = null; // Contains the locked object if the object is locked
	protected ?string $owner = null; // The Nextcloud user that owns this object
	protected ?array $authorization = []; // JSON object describing authorizations
	protected ?DateTime $updated = null;
	protected ?DateTime $created = null;
	protected ?string $folder = null; // The folder path where this object is stored

	/**
	 * Initialize the entity and define field types
	 */
	public function __construct() {
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
		$this->addType(fieldName:'updated', type: 'datetime');
		$this->addType(fieldName:'created', type: 'datetime');
		$this->addType(fieldName:'folder', type: 'string');
	}

	/**
	 * Get the object data
	 *
	 * @return array The object data or empty array if null
	 */
	public function getObject(): array
	{
		return $this->object ?? [];
	}	

	/**
	 * Get the files data
	 *
	 * @return array The files data or empty array if null
	 */
	public function getFiles(): array
	{
		return $this->files ?? [];
	}	

	/**
	 * Get the relations data
	 *
	 * @return array The relations data or empty array if null
	 */
	public function getRelations(): array
	{
		return $this->relations ?? [];
	}

	/**
	 * Get the locked data
	 *
	 * @return array The locked data or empty array if null
	 */
	public function getlocked(): ?array
	{
		return $this->locked;
	}

	/**
	 * Get the authorization data
	 *
	 * @return array The authorization data or empty array if null
	 */
	public function getAuthorization(): ?array
	{
		return $this->authorization;
	}

	/**
	 * Get array of field names that are JSON type
	 *
	 * @return array List of field names that are JSON type
	 */
	public function getJsonFields(): array
	{
		return array_keys(
			array_filter($this->getFieldTypes(), function ($field) {
				return $field === 'json';
			})
		);
	}

	/**
	 * Hydrate the entity from an array of data
	 *
	 * @param array $object Array of data to hydrate the entity with
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
			} catch (\Exception $exception) {
			}
		}

		return $this;
	}

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
		$metadata = [
			'@self' => array_filter($this->getObjectArray(), function($key) {
				return in_array($key, ['object', 'textRepresentation', 'authorization']) === false;
			}, ARRAY_FILTER_USE_KEY)
		];
		return array_merge($metadata, $this->object);
	}

	/**
	 * Get array representation of all object properties
	 *
	 * @return array Array containing all object properties
	 */
	public function getObjectArray(): array
	{
		return [
			'id' => $this->id,
			'uuid' => $this->uuid,
			'uri' => $this->uri,
			'version'     => $this->version,
			'register' => $this->register,
			'schema' => $this->schema,
			'object' => $this->object,
			'files' => $this->files,
			'relations' => $this->relations,
			'textRepresentation' => $this->textRepresentation,
			'locked' => $this->locked,
			'owner' => $this->owner,
			'authorization' => $this->authorization,
			'updated' => isset($this->updated) ? $this->updated->format('c') : null,
			'created' => isset($this->created) ? $this->created->format('c') : null,
			'folder' => $this->folder
		];
	}

	/**
	 * Lock the object for a specific duration
	 *
	 * @param IUserSession $userSession Current user session
	 * @param string|null $process Optional process identifier
	 * @param int|null $duration Lock duration in seconds (default: 1 hour)
	 * @return bool True if lock was successful
	 * @throws \Exception If object is already locked by another user
	 */
	public function lock(IUserSession $userSession, ?string $process = null, ?int $duration = 3600): bool
	{
		$currentUser = $userSession->getUser();
		if (!$currentUser) {
			throw new \Exception('No user logged in');
		}

		$userId = $currentUser->getUID();
		$now = new \DateTime();

		// If already locked, check if it's the same user and not expired
		if ($this->isLocked()) {
			$lock = $this->locked;
			
			// If locked by different user
			if ($lock['user'] !== $userId) {
				throw new \Exception('Object is locked by another user');
			}

			// If same user, extend the lock
			$expirationDate = new \DateTime($lock['expiration']);
			$newExpiration = clone $now;
			$newExpiration->add(new \DateInterval('PT' . $duration . 'S'));

			$this->locked = [
				'user' => $userId,
				'process' => $process ?? $lock['process'],
				'created' => $lock['created'],
				'duration' => $duration,
				'expiration' => $newExpiration->format('c')
			];
		} else {
			// Create new lock
			$expiration = clone $now;
			$expiration->add(new \DateInterval('PT' . $duration . 'S'));

			$this->locked = [
				'user' => $userId,
				'process' => $process,
				'created' => $now->format('c'),
				'duration' => $duration,
				'expiration' => $expiration->format('c')
			];
		}

		return true;
	}

	/**
	 * Unlock the object
	 *
	 * @param IUserSession $userSession Current user session
	 * @return bool True if unlock was successful
	 * @throws \Exception If object is locked by another user
	 */
	public function unlock(IUserSession $userSession): bool
	{
		if (!$this->isLocked()) {
			return true;
		}

		$currentUser = $userSession->getUser();
		if (!$currentUser) {
			throw new \Exception('No user logged in');
		}

		$userId = $currentUser->getUID();
		
		// Check if locked by different user
		if ($this->locked['user'] !== $userId) {
			throw new \Exception('Object is locked by another user');
		}

		$this->locked = null;
		return true;
	}

	/**
	 * Check if the object is currently locked
	 *
	 * @return bool True if object is locked and lock hasn't expired
	 */
	public function isLocked(): bool
	{
		if (!$this->locked) {
			return false;
		}

		// Check if lock has expired
		$now = new \DateTime();
		$expiration = new \DateTime($this->locked['expiration']);

		return $now < $expiration;
	}

	/**
	 * Get lock information
	 *
	 * @return array|null Lock information or null if not locked
	 */
	public function getLockInfo(): ?array
	{
		if (!$this->isLocked()) {
			return null;
		}

		return $this->locked;
	}
}
