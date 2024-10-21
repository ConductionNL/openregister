<?php

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

class AuditTrail extends Entity implements JsonSerializable
{
	protected ?string $uuid = null;
	protected ?int $object = null;
	protected ?string $action = null;
	protected ?array $changed = null;
	protected ?string $user = null;
	protected ?string $userName = null;
	protected ?string $session = null;
	protected ?string $request = null;
	protected ?string $ipAddress = null;
	protected ?DateTime $created = null;

	public function __construct() {
		$this->addType(fieldName: 'uuid', type: 'string');
		$this->addType(fieldName: 'object', type: 'integer');
		$this->addType(fieldName: 'action', type: 'string');
		$this->addType(fieldName: 'changed', type: 'json');
		$this->addType(fieldName: 'user', type: 'string');
		$this->addType(fieldName: 'userName', type: 'string');
		$this->addType(fieldName: 'session', type: 'string');
		$this->addType(fieldName: 'request', type: 'string');
		$this->addType(fieldName: 'ipAddress', type: 'string');
		$this->addType(fieldName: 'created', type: 'datetime');
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
			'object' => $this->object,
			'action' => $this->action,
			'changed' => $this->changed,
			'user' => $this->user,
			'userName' => $this->userName,
			'session' => $this->session,
			'request' => $this->request,
			'ipAddress' => $this->ipAddress,
			'created' => isset($this->created) ? $this->created->format('c') : null
		];
	}
}
