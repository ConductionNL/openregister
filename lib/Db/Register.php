<?php

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

class Register extends Entity implements JsonSerializable
{
	protected ?string $uuid = null;
	protected ?string $title = null;
	protected ?string $description = null;
	protected ?string $version = null;
	protected ?array $schemas = [];
	protected ?string $source = null;
	protected ?string $tablePrefix = null;
	protected ?DateTime $updated = null;
	protected ?DateTime $created = null;

	public function __construct() {
        $this->addType('uuid', 'string');
		$this->addType(fieldName: 'title', type: 'string');
		$this->addType(fieldName: 'description', type: 'string');
		$this->addType(fieldName: 'version', type: 'string');
		$this->addType(fieldName: 'schemas', type: 'json');
		$this->addType(fieldName: 'source', type: 'string');
		$this->addType(fieldName: 'tablePrefix', type: 'string');
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

		if(isset($object['metadata']) === false) {
			$object['metadata'] = [];
		}

		foreach($object as $key => $value) {
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
			'title' => $this->title,
			'description' => $this->description,
			'version' => $this->version,
			'schemas' => $this->schemas,
			'source' => $this->source,
			'tablePrefix' => $this->tablePrefix,
			'updated' => isset($this->updated) ? $this->updated->format('c') : null,
			'created' => isset($this->created) ? $this->created->format('c') : null
		];
	}
}