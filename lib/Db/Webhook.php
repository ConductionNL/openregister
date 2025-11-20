<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Webhook entity
 *
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
 */
class Webhook extends Entity implements JsonSerializable
{

    protected string $uuid = '';
    protected string $name = '';
    protected string $url = '';
    protected string $method = 'POST';
    protected string $events = '[]';
    protected ?string $headers = null;
    protected ?string $secret = null;
    protected bool $enabled = true;
    protected ?string $organisation = null;
    protected ?string $filters = null;
    protected string $retryPolicy = 'exponential';
    protected int $maxRetries = 3;
    protected int $timeout = 30;
    protected ?DateTime $lastTriggeredAt = null;
    protected ?DateTime $lastSuccessAt = null;
    protected ?DateTime $lastFailureAt = null;
    protected int $totalDeliveries = 0;
    protected int $successfulDeliveries = 0;
    protected int $failedDeliveries = 0;
    protected ?DateTime $created = null;
    protected ?DateTime $updated = null;


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
     * @return array
     */
    public function jsonSerialize(): array
    {
        return [
            'id'                    => $this->id,
            'uuid'                  => $this->uuid,
            'name'                  => $this->name,
            'url'                   => $this->url,
            'method'                => $this->method,
            'events'                => $this->getEventsArray(),
            'headers'               => $this->getHeadersArray(),
            'secret'                => $this->secret !== null ? '***' : null,
            'enabled'               => $this->enabled,
            'organisation'          => $this->organisation,
            'filters'               => $this->getFiltersArray(),
            'retryPolicy'           => $this->retryPolicy,
            'maxRetries'            => $this->maxRetries,
            'timeout'               => $this->timeout,
            'lastTriggeredAt'       => $this->lastTriggeredAt?->format('c'),
            'lastSuccessAt'         => $this->lastSuccessAt?->format('c'),
            'lastFailureAt'         => $this->lastFailureAt?->format('c'),
            'totalDeliveries'       => $this->totalDeliveries,
            'successfulDeliveries'  => $this->successfulDeliveries,
            'failedDeliveries'      => $this->failedDeliveries,
            'created'               => $this->created?->format('c'),
            'updated'               => $this->updated?->format('c'),
        ];

    }//end jsonSerialize()


    /**
     * Hydrate entity from array
     *
     * @param array $object Object data
     *
     * @return self
     */
    public function hydrate(array $object): self
    {
        if (isset($object['id']) === true) {
            $this->setId($object['id']);
        }

        if (isset($object['uuid']) === true) {
            $this->setUuid($object['uuid']);
        }

        if (isset($object['name']) === true) {
            $this->setName($object['name']);
        }

        if (isset($object['url']) === true) {
            $this->setUrl($object['url']);
        }

        if (isset($object['method']) === true) {
            $this->setMethod($object['method']);
        }

        if (isset($object['events']) === true) {
            if (is_array($object['events']) === true) {
                $this->setEventsArray($object['events']);
            } else {
                $this->setEvents($object['events']);
            }
        }

        if (isset($object['headers']) === true) {
            if (is_array($object['headers']) === true) {
                $this->setHeadersArray($object['headers']);
            } else {
                $this->setHeaders($object['headers']);
            }
        }

        if (isset($object['secret']) === true) {
            $this->setSecret($object['secret']);
        }

        if (isset($object['enabled']) === true) {
            $this->setEnabled((bool) $object['enabled']);
        }

        if (isset($object['organisation']) === true) {
            $this->setOrganisation($object['organisation']);
        }

        if (isset($object['filters']) === true) {
            if (is_array($object['filters']) === true) {
                $this->setFiltersArray($object['filters']);
            } else {
                $this->setFilters($object['filters']);
            }
        }

        if (isset($object['retryPolicy']) === true) {
            $this->setRetryPolicy($object['retryPolicy']);
        }

        if (isset($object['maxRetries']) === true) {
            $this->setMaxRetries((int) $object['maxRetries']);
        }

        if (isset($object['timeout']) === true) {
            $this->setTimeout((int) $object['timeout']);
        }

        return $this;

    }//end hydrate()


}//end class

