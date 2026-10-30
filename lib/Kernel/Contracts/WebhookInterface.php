<?php
/**
 * WebhookInterface: what callers outside lib/Db need of a Webhook row.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

use DateTime;
use JsonSerializable;

/**
 * A webhook: a subscriber URL with the events it receives and how deliveries are signed and retried.
 *
 * Implemented by \OCA\OpenRegister\Db\Webhook. The column accessors are
 * served by Nextcloud's Entity::__call, so they are listed as tags here.
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
 */
interface WebhookInterface extends JsonSerializable {

	/**
	 * Get events as array
	 *
	 * @return array
	 */
	public function getEventsArray(): array;

	/**
	 * Set events from array
	 *
	 * @param array $events Events array
	 *
	 * @return void
	 */
	public function setEventsArray(array $events): void;

	/**
	 * Get headers as array
	 *
	 * @return array
	 */
	public function getHeadersArray(): array;

	/**
	 * Set headers from array
	 *
	 * @param array|null $headers Headers array
	 *
	 * @return void
	 */
	public function setHeadersArray(?array $headers): void;

	/**
	 * Get filters as array
	 *
	 * @return array
	 */
	public function getFiltersArray(): array;

	/**
	 * Set filters from array
	 *
	 * @param array|null $filters Filters array
	 *
	 * @return void
	 */
	public function setFiltersArray(?array $filters): void;

	/**
	 * Get configuration as array
	 *
	 * @return array
	 */
	public function getConfigurationArray(): array;

	/**
	 * Set configuration from array
	 *
	 * @param array|null $configuration Configuration array
	 *
	 * @return void
	 */
	public function setConfigurationArray(?array $configuration): void;

	/**
	 * Check if event matches webhook
	 *
	 * @param string $eventClass Event class name
	 *
	 * @return bool
	 */
	public function matchesEvent(string $eventClass): bool;

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
	public function jsonSerialize(): array;

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
	public function hydrate(array $object): static;
}//end interface
