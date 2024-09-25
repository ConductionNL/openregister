<?php

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

class Source extends Entity implements JsonSerializable
{
	protected ?string $id = null;
	protected ?string $name = null;
	protected ?string $description = null;
	protected ?string $databaseUrl = null;
	protected ?string $type = null;
	protected ?DateTime $updated = null;
	protected ?DateTime $created = null;

	public function __construct() {
		$this->addType('id', 'string');
		$this->addType('title', 'string');
		$this->addType('description', 'string');
		$this->addType('databaseUrl', 'string');
		$this->addType('type', 'string');
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
			'databaseUrl' => $this->databaseUrl,
			'type' => $this->type,
			'updated' => $this->updated,
			'created' => $this->created
		];
	}
}