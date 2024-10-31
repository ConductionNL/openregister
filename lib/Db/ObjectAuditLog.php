<?php

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class ObjectAuditLog
 *
 * This class represents an audit log entry for changes made to an ObjectEntity.
 * It keeps track of modifications, including the object's UUID, objectId, changes,
 * expiration date, and the user who made the changes. This allows for a comprehensive
 * history of changes and supports auditing and versioning capabilities for ObjectEntity instances.
 *
 * @package OCA\OpenRegister\Db
 */
class ObjectAuditLog extends Entity implements JsonSerializable
{
	protected ?string $uuid = null;
	protected ?string $objectId = null;
	protected ?array $changes = [];
	protected ?DateTime $expires = null;
	protected ?DateTime $created = null;
	protected ?string $userId = null;

	public function __construct() {
		$this->addType(fieldName:'uuid', type: 'string');
		$this->addType(fieldName:'objectId', type: 'string');
		$this->addType(fieldName:'changes', type: 'json');
		$this->addType(fieldName:'expires', type: 'datetime');
		$this->addType(fieldName:'created', type: 'datetime');
		$this->addType(fieldName:'userId', type: 'string');
	}

	public function getJsonFields(): array
	{
		return array_keys(
			array_filter($this->getFieldTypes(), function ($field) {
				return $field === 'json';
			})
		);
	}

	public function hydrate(array $object): self
	{
		$jsonFields = $this->getJsonFields();

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

	public function jsonSerialize(): array
	{
		return [
			'id' => $this->id,
			'uuid' => $this->uuid,
			'objectId' => $this->objectId,
			'changes' => $this->changes,
			'expires' => isset($this->expires) ? $this->expires->format('c') : null,
			'created' => isset($this->created) ? $this->created->format('c') : null,
			'userId' => $this->userId
		];
	}
}
