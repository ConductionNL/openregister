<?php

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @author  Conduction Development Team <dev@conduction.nl>
 * @license AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 */

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Webhook entity
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getUuid()
 * @method void setUuid(string $uuid)
 * @method string getName()
 * @method void setName(string $name)
 * @method string getUrl()
 * @method void setUrl(string $url)
 * @method string getMethod()
 * @method void setMethod(string $method)
 * @method string getEvents()
 * @method void setEvents(string $events)
 * @method string|null getHeaders()
 * @method void setHeaders(?string $headers)
 * @method string|null getSecret()
 * @method void setSecret(?string $secret)
 * @method bool getEnabled()
 * @method void setEnabled(bool $enabled)
 * @method string|null getOrganisation()
 * @method void setOrganisation(?string $organisation)
 * @method string|null getFilters()
 * @method void setFilters(?string $filters)
 * @method string getRetryPolicy()
 * @method void setRetryPolicy(string $retryPolicy)
 * @method int getMaxRetries()
 * @method void setMaxRetries(int $maxRetries)
 * @method int getTimeout()
 * @method void setTimeout(int $timeout)
 * @method DateTime|null getLastTriggeredAt()
 * @method void setLastTriggeredAt(?DateTime $lastTriggeredAt)
 * @method DateTime|null getLastSuccessAt()
 * @method void setLastSuccessAt(?DateTime $lastSuccessAt)
 * @method DateTime|null getLastFailureAt()
 * @method void setLastFailureAt(?DateTime $lastFailureAt)
 * @method int getTotalDeliveries()
 * @method void setTotalDeliveries(int $totalDeliveries)
 * @method int getSuccessfulDeliveries()
 * @method void setSuccessfulDeliveries(int $successfulDeliveries)
 * @method int getFailedDeliveries()
 * @method void setFailedDeliveries(int $failedDeliveries)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 * @method DateTime|null getUpdated()
 * @method void setUpdated(?DateTime $updated)
 * @method string|null getConfiguration()
 * @method void setConfiguration(?string $configuration)
 *
 * @SuppressWarnings(PHPMD.TooManyFields)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class Webhook extends Entity implements JsonSerializable
{

    /**
     * UUID
     *
     * @var string
     */
    protected string $uuid = '';

    /**
     * Name
     *
     * @var string
     */
    protected string $name = '';

    /**
     * URL
     *
     * @var string
     */
    protected string $url = '';

    /**
     * Method
     *
     * @var string
     */
    protected string $method = 'POST';

    /**
     * Events
     *
     * @var string
     */
    protected string $events = '[]';

    /**
     * Headers
     *
     * @var string|null
     */
    protected ?string $headers = null;

    /**
     * Secret
     *
     * @var string|null
     */
    protected ?string $secret = null;

    /**
     * Enabled
     *
     * @var boolean
     */
    protected bool $enabled = true;

    /**
     * Organisation
     *
     * @var string|null
     */
    protected ?string $organisation = null;

    /**
     * Filters
     *
     * @var string|null
     */
    protected ?string $filters = null;

    /**
     * Retry policy
     *
     * @var string
     */
    protected string $retryPolicy = 'exponential';

    /**
     * Max retries
     *
     * @var integer
     */
    protected int $maxRetries = 3;

    /**
     * Timeout
     *
     * @var integer
     */
    protected int $timeout = 30;

    /**
     * Last triggered at
     *
     * @var DateTime|null
     */
    protected ?DateTime $lastTriggeredAt = null;

    /**
     * Last success at
     *
     * @var DateTime|null
     */
    protected ?DateTime $lastSuccessAt = null;

    /**
     * Last failure at
     *
     * @var DateTime|null
     */
    protected ?DateTime $lastFailureAt = null;

    /**
     * Total deliveries
     *
     * @var integer
     */
    protected int $totalDeliveries = 0;

    /**
     * Successful deliveries
     *
     * @var integer
     */
    protected int $successfulDeliveries = 0;

    /**
     * Failed deliveries
     *
     * @var integer
     */
    protected int $failedDeliveries = 0;

    /**
     * Created
     *
     * @var DateTime|null
     */
    protected ?DateTime $created = null;

    /**
     * Updated
     *
     * @var DateTime|null
     */
    protected ?DateTime $updated = null;

    /**
     * Configuration
     *
     * @var string|null
     */
    protected ?string $configuration = null;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->addType('uuid', 'string');
        $this->addType('name', 'string');
        $this->addType('url', 'string');
        $this->addType('method', 'string');
        $this->addType('events', 'string');
        $this->addType('headers', 'string');
        $this->addType('secret', 'string');
        $this->addType('enabled', 'boolean');
        $this->addType('organisation', 'string');
        $this->addType('filters', 'string');
        $this->addType('retryPolicy', 'string');
        $this->addType('maxRetries', 'integer');
        $this->addType('timeout', 'integer');
        $this->addType('lastTriggeredAt', 'datetime');
        $this->addType('lastSuccessAt', 'datetime');
        $this->addType('lastFailureAt', 'datetime');
        $this->addType('totalDeliveries', 'integer');
        $this->addType('successfulDeliveries', 'integer');
        $this->addType('failedDeliveries', 'integer');
        $this->addType('created', 'datetime');
        $this->addType('updated', 'datetime');
        $this->addType('configuration', 'string');
    }//end __construct()

    /**
     * Get events as array
     *
     * @return array
     */
    public function getEventsArray(): array
    {
        return json_decode($this->events, true) ?? [];
    }//end getEventsArray()

    /**
     * Set events from array
     *
     * @param array $events Events array
     *
     * @return void
     */
    public function setEventsArray(array $events): void
    {
        $this->setEvents(json_encode($events));
    }//end setEventsArray()

    /**
     * Get headers as array
     *
     * @return array
     */
    public function getHeadersArray(): array
    {
        if ($this->headers === null) {
            return [];
        }

        return json_decode($this->headers, true) ?? [];
    }//end getHeadersArray()

    /**
     * Set headers from array
     *
     * @param array|null $headers Headers array
     *
     * @return void
     */
    public function setHeadersArray(?array $headers): void
    {
        if ($headers === null) {
            $this->setHeaders(null);
            return;
        }

        $this->setHeaders(json_encode($headers));
    }//end setHeadersArray()

    /**
     * Get filters as array
     *
     * @return array
     */
    public function getFiltersArray(): array
    {
        if ($this->filters === null) {
            return [];
        }

        return json_decode($this->filters, true) ?? [];
    }//end getFiltersArray()

    /**
     * Set filters from array
     *
     * @param array|null $filters Filters array
     *
     * @return void
     */
    public function setFiltersArray(?array $filters): void
    {
        if ($filters === null) {
            $this->setFilters(null);
            return;
        }

        $this->setFilters(json_encode($filters));
    }//end setFiltersArray()

    /**
     * Get configuration as array
     *
     * @return array
     */
    public function getConfigurationArray(): array
    {
        if ($this->configuration === null) {
            return [];
        }

        return json_decode($this->configuration, true) ?? [];
    }//end getConfigurationArray()

    /**
     * Set configuration from array
     *
     * @param array|null $configuration Configuration array
     *
     * @return void
     */
    public function setConfigurationArray(?array $configuration): void
    {
        if ($configuration === null) {
            $this->setConfiguration(null);
            return;
        }

        $this->setConfiguration(json_encode($configuration));
    }//end setConfigurationArray()

    /**
     * Check if event matches webhook
     *
     * @param string $eventClass Event class name
     *
     * @return bool
     */
    public function matchesEvent(string $eventClass): bool
    {
        $events = $this->getEventsArray();

        // Empty events means listen to all.
        if (empty($events) === true) {
            return true;
        }

        // Check if event class is in the list.
        if (in_array($eventClass, $events) === true) {
            return true;
        }

        // Check for wildcard patterns.
        foreach ($events as $pattern) {
            if (fnmatch($pattern, $eventClass) === true) {
                return true;
            }
        }

        return false;
    }//end matchesEvent()

    /**
     * JSON serialize the entity
     *
     * @return (array|bool|int|null|string)[]
     *
     * @psalm-return array{id: int, uuid: string, name: string, url: string,
     *     method: string, events: array, headers: array,
     *     secret: '***'|null, enabled: bool, organisation: null|string,
     *     filters: array, retryPolicy: string, maxRetries: int, timeout: int,
     *     lastTriggeredAt: null|string, lastSuccessAt: null|string,
     *     lastFailureAt: null|string, totalDeliveries: int,
     *     successfulDeliveries: int, failedDeliveries: int,
     *     created: null|string, updated: null|string, configuration: array}
     */
    public function jsonSerialize(): array
    {
        $secretValue = null;
        if ($this->secret !== null) {
            $secretValue = '***';
        }

        return [
            'id'                   => $this->id,
            'uuid'                 => $this->uuid,
            'name'                 => $this->name,
            'url'                  => $this->url,
            'method'               => $this->method,
            'events'               => $this->getEventsArray(),
            'headers'              => $this->getHeadersArray(),
            'secret'               => $secretValue,
            'enabled'              => $this->enabled,
            'organisation'         => $this->organisation,
            'filters'              => $this->getFiltersArray(),
            'retryPolicy'          => $this->retryPolicy,
            'maxRetries'           => $this->maxRetries,
            'timeout'              => $this->timeout,
            'lastTriggeredAt'      => $this->lastTriggeredAt?->format('c'),
            'lastSuccessAt'        => $this->lastSuccessAt?->format('c'),
            'lastFailureAt'        => $this->lastFailureAt?->format('c'),
            'totalDeliveries'      => $this->totalDeliveries,
            'successfulDeliveries' => $this->successfulDeliveries,
            'failedDeliveries'     => $this->failedDeliveries,
            'created'              => $this->created?->format('c'),
            'updated'              => $this->updated?->format('c'),
            'configuration'        => $this->getConfigurationArray(),
        ];
    }//end jsonSerialize()

    /**
     * Hydrate entity from array
     *
     * @param array $object Object data
     *
     * @return static The hydrated entity
     *
     * @SuppressWarnings(PHPMD.NPathComplexity)      Hydration requires handling many optional fields
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function hydrate(array $object): static
    {
        if (($object['id'] ?? null) !== null) {
            $this->setId($object['id']);
        }

        if (($object['uuid'] ?? null) !== null) {
            $this->setUuid($object['uuid']);
        }

        if (($object['name'] ?? null) !== null) {
            $this->setName($object['name']);
        }

        if (($object['url'] ?? null) !== null) {
            $this->setUrl($object['url']);
        }

        if (($object['method'] ?? null) !== null) {
            $this->setMethod($object['method']);
        }

        if (($object['events'] ?? null) !== null) {
            if (is_array($object['events']) === true) {
                $this->setEventsArray($object['events']);
            }

            if (is_array($object['events']) === false) {
                $this->setEvents($object['events']);
            }
        }

        if (($object['headers'] ?? null) !== null) {
            if (is_array($object['headers']) === true) {
                $this->setHeadersArray($object['headers']);
            }

            if (is_array($object['headers']) === false) {
                $this->setHeaders($object['headers']);
            }
        }

        if (($object['secret'] ?? null) !== null) {
            $this->setSecret($object['secret']);
        }

        if (($object['enabled'] ?? null) !== null) {
            $this->setEnabled((bool) $object['enabled']);
        }

        if (($object['organisation'] ?? null) !== null) {
            $this->setOrganisation($object['organisation']);
        }

        if (($object['filters'] ?? null) !== null) {
            if (is_array($object['filters']) === true) {
                $this->setFiltersArray($object['filters']);
            }

            if (is_array($object['filters']) === false) {
                $this->setFilters($object['filters']);
            }
        }

        if (($object['retryPolicy'] ?? null) !== null) {
            $this->setRetryPolicy($object['retryPolicy']);
        }

        if (($object['maxRetries'] ?? null) !== null) {
            $this->setMaxRetries((int) $object['maxRetries']);
        }

        if (($object['timeout'] ?? null) !== null) {
            $this->setTimeout((int) $object['timeout']);
        }

        if (($object['configuration'] ?? null) !== null) {
            if (is_array($object['configuration']) === true) {
                $this->setConfigurationArray($object['configuration']);
            }

            if (is_array($object['configuration']) === false) {
                $this->setConfiguration($object['configuration']);
            }
        }

        return $this;
    }//end hydrate()
}//end class
