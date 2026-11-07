<?php

/**
 * OpenRegister Webhook
 *
 * This file contains the entity class for webhook subscriptions
 * in the OpenRegister application.
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
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
 * @method int|null getMapping()
 * @method void setMapping(?int $mapping)
 *
 * @SuppressWarnings(PHPMD.TooManyFields)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class Webhook extends Entity implements JsonSerializable {
	/**
	 * The fields hydrate() sets, in order: [setter, setter for an array value or null, cast].
	 */
	private const HYDRATED_FIELDS = [
		'id' => ['setId', null, 'plain'],
		'uuid' => ['setUuid', null, 'plain'],
		'name' => ['setName', null, 'plain'],
		'url' => ['setUrl', null, 'plain'],
		'method' => ['setMethod', null, 'plain'],
		'events' => ['setEvents', 'setEventsArray', 'plain'],
		'headers' => ['setHeaders', 'setHeadersArray', 'plain'],
		'secret' => ['setSecret', null, 'plain'],
		'enabled' => ['setEnabled', null, 'bool'],
		'organisation' => ['setOrganisation', null, 'plain'],
		'filters' => ['setFilters', 'setFiltersArray', 'plain'],
		'retryPolicy' => ['setRetryPolicy', null, 'plain'],
		'maxRetries' => ['setMaxRetries', null, 'int'],
		'timeout' => ['setTimeout', null, 'int'],
		'configuration' => ['setConfiguration', 'setConfigurationArray', 'plain'],
	];


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
	 * Mapping ID reference for payload transformation
	 *
	 * @var integer|null
	 */
	protected ?int $mapping = null;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->addType(fieldName: 'uuid', type: 'string');
		$this->addType(fieldName: 'name', type: 'string');
		$this->addType(fieldName: 'url', type: 'string');
		$this->addType(fieldName: 'method', type: 'string');
		$this->addType(fieldName: 'events', type: 'string');
		$this->addType(fieldName: 'headers', type: 'string');
		$this->addType(fieldName: 'secret', type: 'string');
		$this->addType(fieldName: 'enabled', type: 'boolean');
		$this->addType(fieldName: 'organisation', type: 'string');
		$this->addType(fieldName: 'filters', type: 'string');
		$this->addType(fieldName: 'retryPolicy', type: 'string');
		$this->addType(fieldName: 'maxRetries', type: 'integer');
		$this->addType(fieldName: 'timeout', type: 'integer');
		$this->addType(fieldName: 'lastTriggeredAt', type: 'datetime');
		$this->addType(fieldName: 'lastSuccessAt', type: 'datetime');
		$this->addType(fieldName: 'lastFailureAt', type: 'datetime');
		$this->addType(fieldName: 'totalDeliveries', type: 'integer');
		$this->addType(fieldName: 'successfulDeliveries', type: 'integer');
		$this->addType(fieldName: 'failedDeliveries', type: 'integer');
		$this->addType(fieldName: 'created', type: 'datetime');
		$this->addType(fieldName: 'updated', type: 'datetime');
		$this->addType(fieldName: 'configuration', type: 'string');
		$this->addType(fieldName: 'mapping', type: 'integer');
	}//end __construct()

	/**
	 * Get events as array
	 *
	 * @return array
	 */
	public function getEventsArray(): array {
		return json_decode($this->events, true) ?? [];
	}//end getEventsArray()

	/**
	 * Set events from array
	 *
	 * @param array $events Events array
	 *
	 * @return void
	 */
	public function setEventsArray(array $events): void {
		// phpcs:disable CustomSniffs.Functions.NamedParameters -- Entity __call breaks with named args.
		$this->setEvents(json_encode(value: $events));
		// phpcs:enable CustomSniffs.Functions.NamedParameters
	}//end setEventsArray()

	/**
	 * Get headers as array
	 *
	 * @return array
	 */
	public function getHeadersArray(): array {
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
	public function setHeadersArray(?array $headers): void {
		// phpcs:disable CustomSniffs.Functions.NamedParameters -- Entity __call breaks with named args.
		if ($headers === null) {
			$this->setHeaders(null);
			return;
		}

		$this->setHeaders(json_encode(value: $headers));
		// phpcs:enable CustomSniffs.Functions.NamedParameters
	}//end setHeadersArray()

	/**
	 * Get filters as array
	 *
	 * @return array
	 */
	public function getFiltersArray(): array {
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
	public function setFiltersArray(?array $filters): void {
		// phpcs:disable CustomSniffs.Functions.NamedParameters -- Entity __call breaks with named args.
		if ($filters === null) {
			$this->setFilters(null);
			return;
		}

		$this->setFilters(json_encode(value: $filters));
		// phpcs:enable CustomSniffs.Functions.NamedParameters
	}//end setFiltersArray()

	/**
	 * Get configuration as array
	 *
	 * @return array
	 */
	public function getConfigurationArray(): array {
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
	public function setConfigurationArray(?array $configuration): void {
		// phpcs:disable CustomSniffs.Functions.NamedParameters -- Entity __call breaks with named args.
		if ($configuration === null) {
			$this->setConfiguration(null);
			return;
		}

		$this->setConfiguration(json_encode(value: $configuration));
		// phpcs:enable CustomSniffs.Functions.NamedParameters
	}//end setConfigurationArray()

	/**
	 * Check if event matches webhook
	 *
	 * @param string $eventClass Event class name
	 *
	 * @return bool
	 */
	public function matchesEvent(string $eventClass): bool {
		$events = $this->getEventsArray();

		// Empty events means listen to all.
		if (empty($events) === true) {
			return true;
		}

		// Check if event class is in the list.
		if (in_array($eventClass, $events) === true) {
			return true;
		}

		// Check for wildcard patterns. A backslash in a namespaced event class is
		// literal, so it must not act as fnmatch's escape character.
		foreach ($events as $pattern) {
			if (fnmatch($pattern, $eventClass, FNM_NOESCAPE) === true) {
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
	 *     created: null|string, updated: null|string, configuration: array,
	 *     mapping: int|null}
	 */
	public function jsonSerialize(): array {
		$secretValue = null;
		if ($this->secret !== null) {
			$secretValue = '***';
		}

		return [
			'id' => $this->id,
			'uuid' => $this->uuid,
			'name' => $this->name,
			'url' => $this->url,
			'method' => $this->method,
			'events' => $this->getEventsArray(),
			'headers' => $this->getHeadersArray(),
			'secret' => $secretValue,
			'enabled' => $this->enabled,
			'organisation' => $this->organisation,
			'filters' => $this->getFiltersArray(),
			'retryPolicy' => $this->retryPolicy,
			'maxRetries' => $this->maxRetries,
			'timeout' => $this->timeout,
			'lastTriggeredAt' => $this->lastTriggeredAt?->format('c'),
			'lastSuccessAt' => $this->lastSuccessAt?->format('c'),
			'lastFailureAt' => $this->lastFailureAt?->format('c'),
			'totalDeliveries' => $this->totalDeliveries,
			'successfulDeliveries' => $this->successfulDeliveries,
			'failedDeliveries' => $this->failedDeliveries,
			'created' => $this->created?->format('c'),
			'updated' => $this->updated?->format('c'),
			'configuration' => $this->getConfigurationArray(),
			'mapping' => $this->mapping,
		];
	}//end jsonSerialize()

	/**
	 * Hydrate entity from array
	 *
	 * Every field in HYDRATED_FIELDS is set when present and not null: a field
	 * with an array setter takes an array through it and a string as is, and
	 * `bool` and `int` fields are cast. A `mapping` sent as null or empty clears
	 * it; a body that leaves the key out keeps it.
	 *
	 * @param array $object Object data
	 *
	 * @return static The hydrated entity
	 *
	 * @spec openspec/specs/webhook-payload-mapping/spec.md
	 */
	public function hydrate(array $object): static {
		// phpcs:disable CustomSniffs.Functions.NamedParameters -- Entity __call breaks with named args.
		foreach (self::HYDRATED_FIELDS as $field => [$setter, $arraySetter, $kind]) {
			$value = ($object[$field] ?? null);
			if ($value === null) {
				continue;
			}

			if ($arraySetter !== null && is_array($value) === true) {
				$setter = $arraySetter;
			}

			$this->$setter($this->castHydrated(value: $value, kind: $kind));
		}

		if (($object['mapping'] ?? null) !== null && $object['mapping'] !== '') {
			$this->setMapping((int)$object['mapping']);
		} elseif (array_key_exists('mapping', $object) === true) {
			$this->setMapping(null);
		}

		return $this;
		// phpcs:enable
	}//end hydrate()

	/**
	 * A hydrated value cast to its field's kind.
	 *
	 * @param mixed  $value The value.
	 * @param string $kind  One of plain, bool or int.
	 *
	 * @return mixed
	 *
	 * @spec openspec/specs/webhook-payload-mapping/spec.md
	 */
	private function castHydrated(mixed $value, string $kind): mixed {
		return match ($kind) {
			'bool' => (bool)$value,
			'int' => (int)$value,
			default => $value,
		};
	}//end castHydrated()
}//end class
