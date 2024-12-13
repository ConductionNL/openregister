<?php

namespace OCA\OpenRegister\Db;

use DateTime;
use Exception;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;
use OCP\IURLGenerator;

class File extends Entity implements JsonSerializable
{
	/**
	 * @var string The unique identifier for the file.
	 */
	protected string $uuid;

	/**
	 * @var string The name of the file.
	 */
	protected string $filename;

	/**
	 * @var string The URL to download the file.
	 */
	protected string $downloadUrl;

	/**
	 * @var string The URL to share the file.
	 */
	protected string $shareUrl;

	/**
	 * @var string The URL to access the file.
	 */
	protected string $accessUrl;

	/**
	 * @var string The file extension (e.g., .txt, .jpg).
	 */
	protected string $extension;

	/**
	 * @var string The checksum of the file for integrity verification.
	 */
	protected string $checksum;

	/**
	 * @var string The source of the file.
	 */
	protected string $source;

	/**
	 * @var string The ID of the user associated with the file.
	 */
	protected string $userId;

	/**
	 * @var DateTime The date and time when the file was created.
	 */
	protected DateTime $created;

	/**
	 * @var DateTime The date and time when the file was last updated.
	 */
	protected DateTime $updated;

	/**
	 * Constructor for the File entity.
	 */
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

	/**
	 * Retrieves the fields that should be treated as JSON.
	 *
	 * @return array List of JSON field names.
	 */
	public function getJsonFields(): array
	{
		return array_keys(
			array_filter($this->getFieldTypes(), function ($field) {
				return $field === 'json';
			})
		);
	}

	/**
	 * Populates the entity with data from an array.
	 *
	 * @param array $object Data to populate the entity.
	 *
	 * @return self The hydrated entity.
	 */
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
			} catch (Exception $exception) {
				// Log or handle the exception.
			}
		}

		return $this;
	}

	/**
	 * Serializes the entity to a JSON-compatible array.
	 *
	 * @return array The serialized entity data.
	 */
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
			'created' => isset($this->created) === true ? $this->created->format('c') : null,
			'updated' => isset($this->updated) === true ? $this->updated->format('c') : null,
		];
	}

	/**
	 * Generates a JSON schema for the File entity.
	 *
	 * @param IURLGenerator $IURLGenerator The URL generator instance.
	 *
	 * @return string The JSON schema as a string.
	 */
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
