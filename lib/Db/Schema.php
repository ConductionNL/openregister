<?php

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

class Schema extends Entity implements JsonSerializable
{
	protected ?string $title       = null;
	protected ?string $version     = null;
	protected ?string $description = null;
	protected ?string $summary     = null;
	protected ?array  $required    = [];
	protected ?array  $properties  = [];
	protected ?array  $archive     = [];
	protected ?string $source      = null;
	protected ?DateTime $updated = null;
	protected ?DateTime $created = null;

	public function __construct() {
		$this->addType(fieldName: 'title', type: 'string');
		$this->addType(fieldName: 'version', type: 'string');
		$this->addType(fieldName: 'description', type: 'string');
		$this->addType(fieldName: 'summary', type: 'string');
		$this->addType(fieldName: 'required', type: 'json');
		$this->addType(fieldName: 'properties', type: 'json');
		$this->addType(fieldName: 'archive', type: 'json');
		$this->addType(fieldName: 'source', type: 'string');
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
        $properties = [];
        foreach ($this->properties as $key => $property) {
            $properties[$key] = $property;
            if (isset($property['type']) === false) {
                $properties[$key] = $property;
                continue;
            }
            switch ($property['format']) {
                case 'string':
                // For now array as string    
                case 'array':
                    $properties[$key]['default'] = (string) $property;
                    break;
                case 'int':
                case 'integer':
                case 'number':
                    $properties[$key]['default'] = (int) $property;
                    break;
                case 'bool':
                    $properties[$key]['default'] = (bool) $property;
                    break;

            }
        }

		$array = [
			'id'          => $this->id,
			'title'       => $this->title,
			'version'     => $this->version,
			'description' => $this->description,
			'summary'     => $this->summary,
			'required'    => $this->required,
			'properties'  => $properties,
			'archive'     => $this->archive,
			'source'	  => $this->source,
			'updated' => isset($this->updated) ? $this->updated->format('c') : null,
			'created' => isset($this->created) ? $this->created->format('c') : null,
		];

		$jsonFields = $this->getJsonFields();

		foreach ($array as $key => $value) {
			if (in_array($key, $jsonFields) === true && $value === null) {
				$array[$key] = [];
			}
		}

		return $array;
	}
}
