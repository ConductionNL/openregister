<?php
/**
 * OpenRegister File
 *
 * This file contains the class for handling file related operations
 * in the OpenRegister application.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Db;

use DateTime;
use Exception;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;
use OCP\IURLGenerator;

/**
 * File entity class
 *
 * Represents a file in the OpenRegister application
 *
 * @package OCA\OpenRegister\Db
 */
class File extends Entity implements JsonSerializable
{

    /**
     * The unique identifier for the file
     *
     * @var string|null The unique identifier for the file
     */
    protected ?string $uuid = null;

    /**
     * The name of the file
     *
     * @var string|null The name of the file
     */
    protected ?string $filename = null;

    /**
     * The URL to download the file
     *
     * @var string|null The URL to download the file
     */
    protected ?string $downloadUrl = null;

    /**
     * The URL to share the file
     *
     * @var string|null The URL to share the file
     */
    protected ?string $shareUrl = null;

    /**
     * The URL to access the file
     *
     * @var string|null The URL to access the file
     */
    protected ?string $accessUrl = null;

    /**
     * The file extension (e.g., .txt, .jpg)
     *
     * @var string|null The file extension (e.g., .txt, .jpg)
     */
    protected ?string $extension = null;

    /**
     * The checksum of the file for integrity verification
     *
     * @var string|null The checksum of the file for integrity verification
     */
    protected ?string $checksum = null;

    /**
     * The source of the file
     *
     * @var integer|null The source of the file
     */
    protected ?int $source = null;

    /**
     * The ID of the user associated with the file
     *
     * @var string|null The ID of the user associated with the file
     */
    protected ?string $userId = null;

    /**
     * The base64 string for this file
     *
     * @var string|null The base64 string for this file
     */
    protected ?string $base64 = null;

    /**
     * The path to this file
     *
     * @var string|null The path to this file
     */
    protected ?string $filePath = null;

    /**
     * The date and time when the file was created
     *
     * @var DateTime|null The date and time when the file was created
     */
    protected ?DateTime $created = null;

    /**
     * The date and time when the file was last updated
     *
     * @var DateTime|null The date and time when the file was last updated
     */
    protected ?DateTime $updated = null;


    /**
     * Constructor for the File entity
     *
     * Sets up field types for all properties
     */
    public function __construct()
    {
        $this->addType('uuid', 'string');
        $this->addType('filename', 'string');
        $this->addType('downloadUrl', 'string');
        $this->addType('shareUrl', 'string');
        $this->addType('accessUrl', 'string');
        $this->addType('extension', 'string');
        $this->addType('checksum', 'string');
        $this->addType('source', 'int');
        $this->addType('userId', 'string');
        $this->addType('base64', 'string');
        $this->addType('filePath', 'string');
        $this->addType('created', 'datetime');
        $this->addType('updated', 'datetime');

    }//end __construct()


    /**
     * Retrieves the fields that should be treated as JSON
     *
     * @return array List of JSON field names
     */
    public function getJsonFields(): array
    {
        return array_keys(
            array_filter(
                $this->getFieldTypes(),
                function ($field) {
                    return $field === 'json';
                }
            )
        );

    }//end getJsonFields()


    /**
     * Populates the entity with data from an array
     *
     * @param array $object Data to populate the entity
     *
     * @return self The hydrated entity
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

    }//end hydrate()


    /**
     * Serializes the entity to a JSON-compatible array
     *
     * @return array The serialized entity data
     */
    public function jsonSerialize(): array
    {
        $created = null;
        if (isset($this->created) === true) {
            $created = $this->created->format('c');
        }

        $updated = null;
        if (isset($this->updated) === true) {
            $updated = $this->updated->format('c');
        }

        return [
            'id'          => $this->id,
            'uuid'        => $this->uuid,
            'filename'    => $this->filename,
            'downloadUrl' => $this->downloadUrl,
            'shareUrl'    => $this->shareUrl,
            'accessUrl'   => $this->accessUrl,
            'extension'   => $this->extension,
            'checksum'    => $this->checksum,
            'source'      => $this->source,
            'userId'      => $this->userId,
            'base64'      => $this->base64,
            'filePath'    => $this->filePath,
            'created'     => $created,
            'updated'     => $updated,
        ];

    }//end jsonSerialize()


    /**
     * Generates a JSON schema for the File entity
     *
     * @param IURLGenerator $IURLGenerator The URL generator instance
     *
     * @return string The JSON schema as a string
     */
    public static function getSchema(IURLGenerator $IURLGenerator): string
    {
        return json_encode(
            [
                '$id'        => $IURLGenerator->getBaseUrl().'/apps/openconnector/api/files/schema',
                '$schema'    => 'https://json-schema.org/draft/2020-12/schema',
                'type'       => 'object',
                'required'   => [],
                'properties' => [
                    'filename'    => [
                        'type'      => 'string',
                        'minLength' => 1,
                        'maxLength' => 255,
                    ],
                    'downloadUrl' => [
                        'type'   => 'string',
                        'format' => 'uri',
                    ],
                    'shareUrl'    => [
                        'type'   => 'string',
                        'format' => 'uri',
                    ],
                    'accessUrl'   => [
                        'type'   => 'string',
                        'format' => 'uri',
                    ],
                    'extension'   => [
                        'type'      => 'string',
                        'maxLength' => 10,
                    ],
                    'checksum'    => [
                        'type' => 'string',
                    ],
                    'source'      => [
                        'type' => 'number',
                    ],
                    'userId'      => [
                        'type' => 'string',
                    ],
                    'base64'      => [
                        'type' => 'string',
                    ],
                ],
            ]
        );

    }//end getSchema()


}//end class
