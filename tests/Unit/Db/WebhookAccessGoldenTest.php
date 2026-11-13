<?php

/**
 * Golden test for Webhook: the secret is never exposed, which events a
 * webhook receives and which it does not, the JSON column accessors and the
 * serialised form. Pinned on the code before its units were split.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\Webhook;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins Webhook's exposed form and event matching.
 */
class WebhookAccessGoldenTest extends TestCase {

	/**
	 * The secret is masked whenever one is stored, and null otherwise.
	 *
	 * @return void
	 */
	public function testSecretIsNeverExposed(): void {
		$webhook = new Webhook();
		$this->assertNull($webhook->jsonSerialize()['secret']);
		$webhook->setSecret('');
		$this->assertSame('***', $webhook->jsonSerialize()['secret']);
		$webhook->setSecret('s3cr3t');
		$this->assertSame('***', $webhook->jsonSerialize()['secret']);
		$this->assertStringNotContainsString('s3cr3t', (string)json_encode($webhook->jsonSerialize()));
	}//end testSecretIsNeverExposed()

	/**
	 * Subscribed events and whether an event class is delivered.
	 *
	 * @return array<string, array{array<int, string>, string, bool}>
	 */
	public static function eventCases(): array {
		return [
			'allowed: no events means all' => [[], 'OCA\\X\\Event\\Created', true],
			'allowed: exact' => [['OCA\\X\\Event\\Created'], 'OCA\\X\\Event\\Created', true],
			'allowed: wildcard' => [['object.*'], 'object.deleted', true],
			'allowed: second pattern' => [['nope', '*.deleted'], 'object.deleted', true],
			'refused: other event' => [['OCA\\X\\Event\\Created'], 'OCA\\X\\Event\\Deleted', false],
			'refused: wildcard elsewhere' => [['schema.*'], 'object.deleted', false],
			'refused: case differs' => [['oca\\x\\event\\created'], 'OCA\\X\\Event\\Created', false],
		];
	}//end eventCases()

	/**
	 * Whether the webhook receives the event.
	 *
	 * @param array<int, string> $events     The subscribed events.
	 * @param string             $eventClass The event class.
	 * @param bool               $receives   Whether it is delivered.
	 *
	 * @return void
	 */
	#[DataProvider('eventCases')]
	public function testMatchesEvent(array $events, string $eventClass, bool $receives): void {
		$webhook = new Webhook();
		$webhook->setEventsArray($events);
		$this->assertSame($receives, $webhook->matchesEvent($eventClass));
	}//end testMatchesEvent()

	/**
	 * The JSON column accessors round-trip, and null clears.
	 *
	 * @return void
	 */
	public function testJsonColumnAccessors(): void {
		$webhook = new Webhook();
		$this->assertSame([], $webhook->getEventsArray());
		$this->assertSame([], $webhook->getHeadersArray());
		$this->assertSame([], $webhook->getFiltersArray());
		$this->assertSame([], $webhook->getConfigurationArray());

		$webhook->setHeadersArray(['X-A' => '1']);
		$webhook->setFiltersArray(['k' => 'v']);
		$webhook->setConfigurationArray(['c' => true]);
		$this->assertSame('{"X-A":"1"}', $webhook->getHeaders());
		$this->assertSame('{"k":"v"}', $webhook->getFilters());
		$this->assertSame('{"c":true}', $webhook->getConfiguration());
		$this->assertSame(['X-A' => '1'], $webhook->getHeadersArray());

		$webhook->setHeadersArray(null);
		$webhook->setFiltersArray(null);
		$webhook->setConfigurationArray(null);
		$this->assertNull($webhook->getHeaders());
		$this->assertNull($webhook->getFilters());
		$this->assertNull($webhook->getConfiguration());
		$webhook->setHeaders('not json');
		$this->assertSame([], $webhook->getHeadersArray());
	}//end testJsonColumnAccessors()

	/**
	 * Hydrate skips nulls, uses the array setters for arrays, casts, and handles mapping.
	 *
	 * @return void
	 */
	public function testHydrate(): void {
		$webhook = new Webhook();
		$this->assertSame(
			$webhook,
			$webhook->hydrate(
				[
					'name' => 'W',
					'url' => null,
					'events' => ['E1'],
					'headers' => '{"h":1}',
					'enabled' => 0,
					'maxRetries' => '7',
					'timeout' => '12',
					'mapping' => '5',
					'unknown' => 'x',
				]
			)
		);
		$this->assertSame('W', $webhook->getName());
		$this->assertSame('', $webhook->getUrl());
		$this->assertSame('["E1"]', $webhook->getEvents());
		$this->assertSame('{"h":1}', $webhook->getHeaders());
		$this->assertFalse($webhook->getEnabled());
		$this->assertSame(7, $webhook->getMaxRetries());
		$this->assertSame(12, $webhook->getTimeout());
		$this->assertSame(5, $webhook->getMapping());

		$webhook->hydrate(['mapping' => '']);
		$this->assertNull($webhook->getMapping());
		$webhook->setMapping(9);
		$webhook->hydrate(['name' => 'Z']);
		$this->assertSame(9, $webhook->getMapping());
		$webhook->hydrate(['mapping' => null]);
		$this->assertNull($webhook->getMapping());
	}//end testHydrate()

	/**
	 * The serialised form and the field types.
	 *
	 * @return void
	 */
	public function testSerialisedForm(): void {
		$webhook = new Webhook();
		$webhook->setId(2);
		$webhook->setUuid('u');
		$webhook->setName('N');
		$webhook->setUrl('https://h');
		$webhook->setEventsArray(['E']);
		$webhook->setSecret('x');
		$webhook->setOrganisation('org');
		$webhook->setLastTriggeredAt(new DateTime('2026-01-01T00:00:00+00:00'));
		$webhook->setCreated(new DateTime('2025-01-01T00:00:00+00:00'));
		$webhook->setMapping(3);
		$this->assertSame(
			[
				'id' => 2,
				'uuid' => 'u',
				'name' => 'N',
				'url' => 'https://h',
				'method' => 'POST',
				'events' => ['E'],
				'headers' => [],
				'secret' => '***',
				'enabled' => true,
				'organisation' => 'org',
				'owner' => null,
				'filters' => [],
				'retryPolicy' => 'exponential',
				'maxRetries' => 3,
				'timeout' => 30,
				'lastTriggeredAt' => '2026-01-01T00:00:00+00:00',
				'lastSuccessAt' => null,
				'lastFailureAt' => null,
				'totalDeliveries' => 0,
				'successfulDeliveries' => 0,
				'failedDeliveries' => 0,
				'created' => '2025-01-01T00:00:00+00:00',
				'updated' => null,
				'configuration' => [],
				'mapping' => 3,
			],
			$webhook->jsonSerialize()
		);
		$this->assertSame(
			['id', 'uuid', 'name', 'url', 'method', 'events', 'headers', 'secret', 'enabled', 'organisation', 'owner', 'filters', 'retryPolicy', 'maxRetries', 'timeout', 'lastTriggeredAt', 'lastSuccessAt', 'lastFailureAt', 'totalDeliveries', 'successfulDeliveries', 'failedDeliveries', 'created', 'updated', 'configuration', 'mapping'],
			array_keys($webhook->getFieldTypes())
		);
	}//end testSerialisedForm()
}//end class
