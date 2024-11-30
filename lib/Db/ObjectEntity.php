<?php

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

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
		$this->addType(fieldName:'updated', type: 'datetime');
		$this->addType(fieldName:'created', type: 'datetime');
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
			} catch (\Exception $exception) {
			}
		}

		return $this;
	}


	public function jsonSerialize(): array
	{
		return $this->object;
	}

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
			'updated' => isset($this->updated) ? $this->updated->format('c') : null,
			'created' => isset($this->created) ? $this->created->format('c') : null
		];
	}

}
