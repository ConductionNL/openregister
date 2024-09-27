<?php

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

class Source extends Entity implements JsonSerializable
{
	protected ?string $title = null;
	protected ?string $description = null;
	protected ?string $databaseUrl = null;
	protected ?string $type = null;
	protected ?DateTime $updated = null;
	protected ?DateTime $created = null;

	public function __construct() {
		$this->addType(fieldName: 'title', type: 'string');
		$this->addType(fieldName: 'description', type: 'string');
		$this->addType(fieldName: 'databaseUrl', type: 'string');
		$this->addType(fieldName: 'type', type: 'string');
		$this->addType(fieldName: 'updated', type: 'datetime');
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
			'title' => $this->title,
			'description' => $this->description,
			'databaseUrl' => $this->databaseUrl,
			'type' => $this->type,
			'updated' => $this->updated,
			'created' => $this->created
		];
	}
}