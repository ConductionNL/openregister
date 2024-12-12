<?php

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;
use OCP\IURLGenerator;

class File extends Entity implements JsonSerializable
{
    protected string $uuid;
    protected string $filename;
	protected string $downloadUrl;
	protected string $shareUrl;
	protected string $accessUrl;
	protected string $extension;
	protected string $checksum;
	protected string $source;
	protected string $userId;
	protected DateTime $created;
	protected DateTime $updated;

	public function __construct() {
        $this->addType('uuid', 'string');
		$this->addType('filename', 'string');
		$this->addType('downloadUrl', 'string');
		$this->addType('shareUrl', 'string');
		$this->addType('accessUrl', 'string');
		$this->addType('extension', 'string');
		$this->addType('checksum', 'string');
		$this->addType('source', 'int');
		$this->addType('userId', 'string');
		$this->addType('created', 'datetime');
		$this->addType('updated', 'datetime');
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
				$value = [];
			}

			$method = 'set'.ucfirst($key);

			try {
				$this->$method($value);
			} catch (\Exception $exception) {
//				("Error writing $key");
			}
		}

		return $this;
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => $this->id,
			'uuid' => $this->uuid,
			'filename' => $this->filename,
			'downloadUrl' => $this->downloadUrl,
			'shareUrl' => $this->shareUrl,
			'accessUrl' => $this->accessUrl,
			'extension' => $this->extension,
			'checksum' => $this->checksum,
			'source' => $this->source,
			'userId' => $this->userId,
            'created' => isset($this->created) ? $this->created->format('c') : null,
			'updated' => isset($this->updated) ? $this->updated->format('c') : null,
		];
	}

	public static function getSchema(IURLGenerator $IURLGenerator): string {
		return json_encode([
			'$id'        => $IURLGenerator->getBaseUrl().'/apps/openconnector/api/files/schema',
			'$schema'    => 'https://json-schema.org/draft/2020-12/schema',
			'type'       => 'object',
			'required'   => [
				'filename',
				'accessUrl',
			],
			'properties' => [
				'filename' => [
					'type' => 'string',
					'minLength' => 1,
					'maxLength' => 255
				],
				'downloadUrl' => [
					'type' => 'string',
					'format' => 'uri',
				],
				'shareUrl'  => [
					'type' => 'string',
					'format' => 'uri',
				],
				'accessUrl'  => [
					'type' => 'string',
					'format' => 'uri',
				],
				'extension' => [
					'type' => 'string',
					'maxLength' => 10,
				],
				'checksum' => [
					'type' => 'string',
				],
				'source' => [
					'type' => 'number',
				],
				'userId' => [
					'type' => 'string',
				]
			]
		]);
	}
}
