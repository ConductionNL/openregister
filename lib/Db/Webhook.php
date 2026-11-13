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
use OCA\OpenRegister\Db\Webhook\WebhookFieldTypes;
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
 * @method string|null getOwner()
 * @method void setOwner(?string $owner)
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
	 * The user who owns this webhook, or null for an administrator's webhook.
	 *
	 * Set by the server, never from a request body: it is deliberately not in
	 * HYDRATED_FIELDS.
	 *
	 * @var string|null
	 */
	protected ?string $owner = null;

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
		foreach (WebhookFieldTypes::TYPES as $fieldName => $type) {
			$this->addType(fieldName: $fieldName, type: $type);
		}
	}//end __construct()

	/**
	 * Get events as array
	 *
	 * @return array
	 */
	public function getEventsArray(): array {
		return $this->decodedColumn(text: $this->events);
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
		return $this->decodedColumn(text: $this->headers);
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
		$this->setHeaders($this->encodedColumn(value: $headers));
		// phpcs:enable CustomSniffs.Functions.NamedParameters
	}//end setHeadersArray()

	/**
	 * Get filters as array
	 *
	 * @return array
	 */
	public function getFiltersArray(): array {
		return $this->decodedColumn(text: $this->filters);
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
		$this->setFilters($this->encodedColumn(value: $filters));
		// phpcs:enable CustomSniffs.Functions.NamedParameters
	}//end setFiltersArray()

	/**
	 * Get configuration as array
	 *
	 * @return array
	 */
	public function getConfigurationArray(): array {
		return $this->decodedColumn(text: $this->configuration);
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
		$this->setConfiguration($this->encodedColumn(value: $configuration));
		// phpcs:enable CustomSniffs.Functions.NamedParameters
	}//end setConfigurationArray()

	/**
	 * A JSON text column as an array: empty when it is null or not JSON.
	 *
	 * @param string|null $text The stored JSON text.
	 *
	 * @return array
	 */
	private function decodedColumn(string|null $text): array {
		if ($text === null) {
			return [];
		}

		return json_decode($text, true) ?? [];
	}//end decodedColumn()

	/**
	 * An array as JSON text for a column; null stays null.
	 *
	 * @param array|null $value The value.
	 *
	 * @return string|false|null
	 */
	private function encodedColumn(array|null $value): string|false|null {
		if ($value === null) {
			return null;
		}

		return json_encode(value: $value);
	}//end encodedColumn()

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
	 *     owner: null|string, filters: array, retryPolicy: string, maxRetries: int, timeout: int,
	 *     lastTriggeredAt: null|string, lastSuccessAt: null|string,
	 *     lastFailureAt: null|string, totalDeliveries: int,
	 *     successfulDeliveries: int, failedDeliveries: int,
	 *     created: null|string, updated: null|string, configuration: array,
	 *     mapping: int|null}
	 */
	public function jsonSerialize(): array {
		return array_merge($this->targetJson(), $this->subscriptionJson(), $this->deliveryJson(), $this->recordJson());
	}//end jsonSerialize()

	/**
	 * Identity and target.
	 *
	 * @return array<string, mixed>
	 */
	private function targetJson(): array {
		return [
			'id' => $this->id,
			'uuid' => $this->uuid,
			'name' => $this->name,
			'url' => $this->url,
			'method' => $this->method,
		];
	}//end targetJson()

	/**
	 * Subscription, headers, filters and tenancy; a stored secret only as `***`.
	 *
	 * @return array<string, mixed>
	 */
	private function subscriptionJson(): array {
		return [
			'events' => $this->getEventsArray(),
			'headers' => $this->getHeadersArray(),
			'secret' => $this->maskedSecret(),
			'enabled' => $this->enabled,
			'organisation' => $this->organisation,
			'owner' => $this->owner,
			'filters' => $this->getFiltersArray(),
		];
	}//end subscriptionJson()

	/**
	 * `***` when a secret is stored, else null: the secret itself is never serialised.
	 *
	 * @return string|null
	 */
	private function maskedSecret(): string|null {
		if ($this->secret !== null) {
			return '***';
		}

		return null;
	}//end maskedSecret()

	/**
	 * Retry policy and delivery history.
	 *
	 * @return array<string, mixed>
	 */
	private function deliveryJson(): array {
		return [
			'retryPolicy' => $this->retryPolicy,
			'maxRetries' => $this->maxRetries,
			'timeout' => $this->timeout,
			'lastTriggeredAt' => $this->lastTriggeredAt?->format('c'),
			'lastSuccessAt' => $this->lastSuccessAt?->format('c'),
			'lastFailureAt' => $this->lastFailureAt?->format('c'),
			'totalDeliveries' => $this->totalDeliveries,
			'successfulDeliveries' => $this->successfulDeliveries,
			'failedDeliveries' => $this->failedDeliveries,
		];
	}//end deliveryJson()

	/**
	 * Timestamps, configuration and mapping.
	 *
	 * @return array<string, mixed>
	 */
	private function recordJson(): array {
		return [
			'created' => $this->created?->format('c'),
			'updated' => $this->updated?->format('c'),
			'configuration' => $this->getConfigurationArray(),
			'mapping' => $this->mapping,
		];
	}//end recordJson()

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
		foreach (self::HYDRATED_FIELDS as $field => $spec) {
			$this->hydrateField(value: ($object[$field] ?? null), spec: $spec);
		}

		$this->hydrateMapping(object: $object);

		return $this;
	}//end hydrate()

	/**
	 * Set one listed field from a given, non-null value, through its array setter for an array.
	 *
	 * @param mixed                            $value The given value.
	 * @param array{string, string|null, string} $spec  The setter, the array setter and the cast.
	 *
	 * @return void
	 */
	private function hydrateField(mixed $value, array $spec): void {
		if ($value === null) {
			return;
		}

		$setter = $spec[0];
		if ($spec[1] !== null && is_array($value) === true) {
			$setter = $spec[1];
		}

		// phpcs:disable CustomSniffs.Functions.NamedParameters -- Entity __call breaks with named args.
		$this->$setter($this->castHydrated(value: $value, kind: $spec[2]));
		// phpcs:enable CustomSniffs.Functions.NamedParameters
	}//end hydrateField()

	/**
	 * Set the mapping id: a given value as an int, an empty or null one clears it, an absent one leaves it.
	 *
	 * @param array<string, mixed> $object The data to hydrate from.
	 *
	 * @return void
	 */
	private function hydrateMapping(array $object): void {
		// phpcs:disable CustomSniffs.Functions.NamedParameters -- Entity __call breaks with named args.
		if (($object['mapping'] ?? null) !== null && $object['mapping'] !== '') {
			$this->setMapping((int)$object['mapping']);
			return;
		}

		if (array_key_exists('mapping', $object) === true) {
			$this->setMapping(null);
		}

		// phpcs:enable CustomSniffs.Functions.NamedParameters
	}//end hydrateMapping()

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
