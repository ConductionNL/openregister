<?php
/**
 * OpenRegister Endpoint Log Entity
 *
 * This file contains the class for handling endpoint log entity related operations
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
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class EndpointLog
 *
 * Represents an endpoint call log entity
 *
 * @package   OCA\OpenRegister\Db
 * @category  Database
 * @author    Conduction Development Team
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2
 * @version   GIT: <git_id>
 * @link      https://OpenRegister.app
 */
class EndpointLog extends Entity implements JsonSerializable
{

    /**
     * Unique identifier for this endpoint log entry.
     *
     * @var string|null Unique identifier for this call log entry
     */
    protected ?string $uuid = null;

    /**
     * HTTP status code returned from the endpoint call.
     *
     * @var integer|null HTTP status code returned from the endpoint call
     */
    protected ?int $statusCode = null;

    /**
     * Status message or description returned with the response.
     *
     * @var string|null Status message or description returned with the response
     */
    protected ?string $statusMessage = null;

    /**
     * Complete request data including headers, method, body, etc.
     *
     * @var array|null Complete request data including headers, method, body, etc
     */
    protected ?array $request = null;

    /**
     * Complete response data including headers, body, and status info.
     *
     * @var array|null Complete response data including headers, body, and status info
     */
    protected ?array $response = null;

    /**
     * Reference to the endpoint that was called.
     *
     * @var integer|null Reference to the endpoint that was called
     */
    protected ?int $endpointId = null;

    /**
     * Identifier of the user who initiated the call.
     *
     * @var string|null Identifier of the user who initiated the call
     */
    protected ?string $userId = null;

    /**
     * Session identifier associated with this call.
     *
     * @var string|null Session identifier associated with this call
     */
    protected ?string $sessionId = null;

    /**
     * When this log entry should expire/be deleted.
     *
     * @var DateTime|null When this log entry should expire/be deleted
     */
    protected ?DateTime $expires = null;

    /**
     * When this log entry was created.
     *
     * @var DateTime|null When this log entry was created
     */
    protected ?DateTime $created = null;

    /**
     * Size of this log entry in bytes (calculated from serialized object).
     *
     * @var integer Size of this log entry in bytes
     */
    protected int $size = 4096;


    /**
     * EndpointLog constructor
     *
     * Initializes field types and sets default values for expires and size properties.
     * The expires date is set to one week from creation, and size defaults to 4KB.
     *
     * @psalm-api
     * @phpstan-api
     */
    public function __construct()
    {
        $this->addType('uuid', 'string');
        $this->addType('statusCode', 'integer');
        $this->addType('statusMessage', 'string');
        $this->addType('request', 'json');
        $this->addType('response', 'json');
        $this->addType('endpointId', 'integer');
        $this->addType('userId', 'string');
        $this->addType('sessionId', 'string');
        $this->addType('expires', 'datetime');
        $this->addType('created', 'datetime');
        $this->addType('size', 'integer');

        // Set default expires to next week.
        if ($this->expires === null) {
            $this->expires = new DateTime('+1 week');
        }

        // Calculate and set object size.
        $this->calculateSize();

    }//end __construct()


    /**
     * Get the request data
     *
     * @return array|null The request data or null
     */
    public function getRequest(): ?array
    {
        return $this->request;

    }//end getRequest()


    /**
     * Get the response data
     *
     * @return array|null The response data or null
     */
    public function getResponse(): ?array
    {
        return $this->response;

    }//end getResponse()


    /**
     * Get array of field names that are JSON type
     *
     * @return array List of field names that are JSON type
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
     * Hydrate the entity from an array of data
     *
     * @param array $object Array of data to hydrate the entity with
     *
     * @return self Returns the hydrated entity
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
            } catch (\Exception $exception) {
                // Handle or log the exception if needed.
            }
        }

        // Recalculate size after hydration to ensure it reflects current data.
        $this->calculateSize();

        return $this;

    }//end hydrate()


    /**
     * Calculate and set the size of this log entry
     *
     * This method calculates the size of the log entry by serializing the object
     * and measuring its byte size. This helps with storage management and cleanup.
     *
     * @return void
     *
     * @psalm-return   void
     * @phpstan-return void
     */
    public function calculateSize(): void
    {
        // Serialize the current object to calculate its size.
        $serialized = json_encode($this->jsonSerialize());
        $this->size = strlen($serialized);

        // Ensure minimum size of 4KB if calculated size is smaller.
        if ($this->size < 4096) {
            $this->size = 4096;
        }

    }//end calculateSize()


    /**
     * Get the size of this log entry in bytes
     *
     * @return int The size in bytes
     *
     * @psalm-return   int
     * @phpstan-return int
     */
    public function getSize(): int
    {
        return $this->size;

    }//end getSize()


    /**
     * Set the size of this log entry in bytes
     *
     * @param int $size The size in bytes
     *
     * @return void
     *
     * @psalm-param    int $size
     * @psalm-return   void
     * @phpstan-param  int $size
     * @phpstan-return void
     */
    public function setSize(int $size): void
    {
        $this->size = $size;

    }//end setSize()


    /**
     * Serialize the entity to JSON format
     *
     * @return         array Serialized endpoint log data
     * @phpstan-return array<string,mixed>
     * @psalm-return   array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id'            => $this->id,
            'uuid'          => $this->uuid,
            'statusCode'    => $this->statusCode,
            'statusMessage' => $this->statusMessage,
            'request'       => $this->request,
            'response'      => $this->response,
            'endpointId'    => $this->endpointId,
            'userId'        => $this->userId,
            'sessionId'     => $this->sessionId,
            'expires'       => isset($this->expires) === true ? $this->expires->format('c') : null,
            'created'       => isset($this->created) === true ? $this->created->format('c') : null,
            'size'          => $this->size,
        ];

    }//end jsonSerialize()


}//end class
