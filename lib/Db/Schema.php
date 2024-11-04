<?php

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;
use OCP\IURLGenerator;
use stdClass;

class Schema extends Entity implements JsonSerializable
{
	protected ?string $uuid = null;
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
	protected bool $hardValidation = false;

	public function __construct() {
		$this->addType(fieldName: 'uuid', type: 'string');
		$this->addType(fieldName: 'title', type: 'string');
		$this->addType(fieldName: 'version', type: 'string');
		$this->addType(fieldName: 'description', type: 'string');
		$this->addType(fieldName: 'summary', type: 'string');
		$this->addType(fieldName: 'required', type: 'json');
		$this->addType(fieldName: 'properties', type: 'json');
		$this->addType(fieldName: 'archive', type: 'json');
		$this->addType(fieldName:'updated', type: 'datetime');
		$this->addType(fieldName:'created', type: 'datetime');
		$this->addType(fieldName:'hardValidation', type: Types::BOOLEAN);
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
			'uuid' => $this->uuid,
			'title'       => $this->title,
			'version'     => $this->version,
			'description' => $this->description,
			'summary'     => $this->summary,
			'required'    => $this->required,
			'properties'  => $properties,
			'hardValidation' => $this->hardValidation,
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

	/**
	 * Creates a decoded JSON Schema object from the information in the schema
	 *
	 * @return object Decoded JSON Schema object.
	 */
	public function getSchemaObject(IURLGenerator $urlGenerator): object
	{
		$data = $this->jsonSerialize();
		$properties = $data['properties'];
		unset($data['properties'], $data['id'], $data['uuid'], $data['summary'], $data['updated'], $data['created']);

		$data['required'] = [];

		$data['type'] = 'object';

		foreach ($properties as $property) {
			$title = $property['title'];
			if ($property['required'] === true) {
				$data['required'][] = $title;
			}
			unset($property['title'], $property['required']);

			// Remove empty fields with array_filter().
			$data['properties'][$title] = array_filter($property);
		}

		$data['$schema'] = 'https://json-schema.org/draft/2020-12/schema';
		$data['$id'] = $urlGenerator->getAbsoluteURL($urlGenerator->linkToRoute('openregister.Schemas.show', ['id' => $this->getUuid()]));


		return json_decode(json_encode($data));
	}
}
