<?php

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class ObjectEntity
 * 
 * Represents an object entity in the OpenRegister system
 * 
 * @package OCA\OpenRegister\Db
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
	protected ?array $locked = [];
	protected ?DateTime $updated = null;
	protected ?DateTime $created = null;

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
		$this->addType(fieldName:'updated', type: 'datetime');
		$this->addType(fieldName:'created', type: 'datetime');
	}

	/**
	 * Get all JSON fields in the entity
	 * 
	 * @return array Array of field names that are JSON type
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
	 * Hydrate the entity with data from an array
	 * 
	 * @param array $object Array of data to hydrate the entity with
	 * @return self
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
	 * Set a lock on the object
	 * 
	 * @param string $userId ID of the user setting the lock
	 * @param DateTime|null $endDate Optional end date for the lock
	 * @return self
	 */
	public function setLock(string $userId, ?DateTime $endDate = null): self {
		$this->locked = [
			'user' => $userId,
			'endDate' => $endDate ? $endDate->format('c') : null
		];
		return $this;
	}

	/**
	 * Remove the lock from the object
	 * 
	 * @return self
	 */
	public function removeLock(): self {
		$this->locked = [];
		return $this;
	}

	/**
	 * Check if the object is locked
	 * 
	 * @return bool True if object is locked
	 */
	public function isLocked(): bool {
		return !empty($this->locked) && 
			   isset($this->locked['user']) && 
			   (!isset($this->locked['endDate']) || 
			    new DateTime($this->locked['endDate']) > new DateTime());
	}

	/**
	 * Get the user ID who locked the object
	 * 
	 * @return string|null User ID or null if not locked
	 */
	public function getLockedBy(): ?string {
		return $this->isLocked() ? $this->locked['user'] : null;
	}

	/**
	 * Get the lock end date
	 * 
	 * @return DateTime|null Lock end date or null if no end date or not locked
	 */
	public function getLockEndDate(): ?DateTime {
		if (!$this->isLocked() || !isset($this->locked['endDate'])) {
			return null;
		}
		return new DateTime($this->locked['endDate']);
	}

	/**
	 * Serialize the object to JSON
	 * 
	 * @return array
	 */
	public function jsonSerialize(): array
	{
		return $this->object;
	}

	/**
	 * Get the object as an array including all properties
	 * 
	 * @return array
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
			'updated' => isset($this->updated) ? $this->updated->format('c') : null,
			'created' => isset($this->created) ? $this->created->format('c') : null
		];
	}
}
