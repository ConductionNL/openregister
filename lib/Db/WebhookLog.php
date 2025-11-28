<?php
/**
 * OpenRegister Webhook Log Entity
 *
 * Entity for logging webhook delivery attempts and results.
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
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * WebhookLog entity
 *
 * Stores logs of webhook delivery attempts, including success/failure status,
 * response data, and retry information.
 *
 * @method int getWebhookId()
 * @method void setWebhookId(int $webhookId)
 * @method string getEventClass()
 * @method void setEventClass(string $eventClass)
 * @method string|null getPayload()
 * @method void setPayload(?string $payload)
 * @method string getUrl()
 * @method void setUrl(string $url)
 * @method string getMethod()
 * @method void setMethod(string $method)
 * @method bool getSuccess()
 * @method void setSuccess(bool $success)
 * @method int|null getStatusCode()
 * @method void setStatusCode(?int $statusCode)
 * @method string|null getRequestBody()
 * @method void setRequestBody(?string $requestBody)
 * @method string|null getResponseBody()
 * @method void setResponseBody(?string $responseBody)
 * @method string|null getErrorMessage()
 * @method void setErrorMessage(?string $errorMessage)
 * @method int getAttempt()
 * @method void setAttempt(int $attempt)
 * @method int|null getNextRetryAt()
 * @method void setNextRetryAt(?DateTime $nextRetryAt)
 * @method DateTime getCreated()
 * @method void setCreated(DateTime $created)
 */
class WebhookLog extends Entity implements JsonSerializable
{

    /**
     * Webhook ID
     *
     * @var integer
     */
    protected int $webhookId = 0;

    /**
     * Event class name
     *
     * @var string
     */
    protected string $eventClass = '';

    /**
     * Payload data (JSON)
     *
     * @var string|null
     */
    protected ?string $payload = null;

    /**
     * Target URL
     *
     * @var string
     */
    protected string $url = '';

    /**
     * HTTP method
     *
     * @var string
     */
    protected string $method = 'POST';

    /**
     * Success status
     *
     * @var boolean
     */
    protected bool $success = false;

    /**
     * HTTP status code
     *
     * @var integer|null
     */
    protected ?int $statusCode = null;

    /**
     * Request body (stored only on failure)
     *
     * @var string|null
     */
    protected ?string $requestBody = null;

    /**
     * Response body
     *
     * @var string|null
     */
    protected ?string $responseBody = null;

    /**
     * Error message
     *
     * @var string|null
     */
    protected ?string $errorMessage = null;

    /**
     * Attempt number
     *
     * @var integer
     */
    protected int $attempt = 1;

    /**
     * Next retry timestamp
     *
     * @var DateTime|null
     */
    protected ?DateTime $nextRetryAt = null;

    /**
     * Created timestamp
     *
     * @var DateTime
     */
    protected DateTime $created;


    /**
     * Constructor
     *
     * @return void
     */
    public function __construct()
    {
        $this->addType('webhookId', 'integer');
        $this->addType('eventClass', 'string');
        $this->addType('payload', 'string');
        $this->addType('url', 'string');
        $this->addType('method', 'string');
        $this->addType('success', 'boolean');
        $this->addType('statusCode', 'integer');
        $this->addType('requestBody', 'string');
        $this->addType('responseBody', 'string');
        $this->addType('errorMessage', 'string');
        $this->addType('attempt', 'integer');
        $this->addType('nextRetryAt', 'datetime');
        $this->addType('created', 'datetime');

        // Initialize created timestamp.
        $this->created = new DateTime();

    }//end __construct()


    /**
     * Get payload as array
     *
     * @return array
     */
    public function getPayloadArray(): array
    {
        if ($this->payload === null) {
            return [];
        }

        return json_decode($this->payload, true) ?? [];

    }//end getPayloadArray()


    /**
     * Set payload from array
     *
     * @param array|null $payload Payload array
     *
     * @return void
     */
    public function setPayloadArray(?array $payload): void
    {
        if ($payload === null) {
            $this->setPayload(null);
            return;
        }

        $this->setPayload(json_encode($payload));

    }//end setPayloadArray()


    /**
     * JSON serialize the entity
     *
     * @return array
     */
    public function jsonSerialize(): array
    {
        return [
            'id'           => $this->id,
            'webhookId'    => $this->webhookId,
            'eventClass'   => $this->eventClass,
            'payload'      => $this->getPayloadArray(),
            'url'          => $this->url,
            'method'       => $this->method,
            'success'      => $this->success,
            'statusCode'   => $this->statusCode,
            'requestBody'  => $this->requestBody,
            'responseBody' => $this->responseBody,
            'errorMessage' => $this->errorMessage,
            'attempt'      => $this->attempt,
            'nextRetryAt'  => $this->nextRetryAt?->format('c'),
            'created'      => $this->created->format('c'),
        ];

    }//end jsonSerialize()


}//end class
