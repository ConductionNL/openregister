<?php

namespace OCA\OpenRegister\Db;

use Exception;
use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

class ObjectEntity extends Entity implements JsonSerializable
{

	/**
	 * Internal id
	 *
	 * @var string|null
	 */
	protected ?string $id      = null;

	/**
	 * External id
	 *
	 * @var string|null
	 */
	protected ?string $uuid      = null;

	protected ?string $register  = null;
	protected ?string $schema    = null;
	protected ?array $object     = [];
	protected ?DateTime $updated = null;
	protected ?DateTime $created = null;

	public function __construct() {
		// Internal id
		$this->addType(fieldName: 'id',       type: 'string');
		// External id
		$this->addType(fieldName: 'uuid',     type: 'string');
		$this->addType(fieldName: 'register', type: 'string');
		$this->addType(fieldName: 'schema',   type: 'string');
		$this->addType(fieldName: 'object',   type: 'json');
		$this->addType(fieldName: 'updated',  type: 'datetime');
		$this->addType(fieldName: 'created',  type: 'datetime');
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
			}
		}

		return $this;
	}


	public function jsonSerialize(): array
	{
		return [
			'id' => $this->id,
			'uuid' => $this->uuid,
			'register' => $this->register,
			'schema' => $this->schema,
			'object' => $this->object,
			'updated' => isset($this->updated) ? $this->updated->format('c') : null,
			'created' => isset($this->created) ? $this->created->format('c') : null
		];
	}

	/**
	 * Get the internal id
	 *
	 * @return string|null
	 */
	public function getId(): ?string
	{
		return $this->id;
	}

	/**
	 * Set the internal id
	 *
	 * @param string $id
	 * @return self
	 */
	public function setId(string $id): self
	{
		$this->id = $id;
		return $this;
	}

	/**
	 * Get the external id
	 *
	 * @return string|null
	 */
	public function getUuid(): ?string
	{
		return $this->uuid;
	}

	/**
	 * Set the external id
	 *
	 * @param string $uuid
	 * @return self
	 */
	public function setUuid(string $uuid): self
	{
		$this->uuid = $uuid;
		return $this;
	}

}
