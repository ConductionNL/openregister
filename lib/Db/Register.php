<?php

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

class Register extends Entity implements JsonSerializable
{
	protected ?string $id = null;
	protected ?string $title = null;
	protected ?string $description = null;
	protected ?array $schemas = null;
	protected ?string $source = null;
	protected ?string $tablePrefix = null;
	protected ?DateTime $updated = null;
	protected ?DateTime $created = null;

	public function __construct() {
		$this->addType('id', 'string');
		$this->addType('title', 'title');
		$this->addType('description', 'string');
		$this->addType('schemas', 'array');
		$this->addType('source', 'string');
		$this->addType('tablePrefix', 'string');
		$this->addType('updated', 'datetime');
		$this->addType('created', 'datetime');
	}

	public function hydrate(array $object): self
	{
		foreach($object as $key => $value) {
			$method = 'set'.ucfirst($key);

			try {
				$this->$method($value);
			} catch (\Exception $exception) {
				// Error handling can be added here
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
			'schemas' => $this->schemas,
			'source' => $this->source,
			'tablePrefix' => $this->tablePrefix,
			'updated' => $this->updated,
			'created' => $this->created
		];
	}
}