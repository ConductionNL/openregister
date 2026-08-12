<?php

/**
 * Webhook interception cache tests.
 *
 * Proves the "has interception webhooks" flag round-trips through the
 * distributed cache (hit for both true and false, miss returns null) and
 * that invalidate() clears the flag prefix.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Webhook
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Webhook;

use OCA\OpenRegister\Service\Webhook\WebhookInterceptionCache;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for WebhookInterceptionCache.
 */
class WebhookInterceptionCacheTest extends TestCase {
	/**
	 * In-memory ICache fake backing the cache under test.
	 *
	 * @var array<string, mixed>
	 */
	private array $store = [];

	/**
	 * Cache under test.
	 *
	 * @var WebhookInterceptionCache
	 */
	private WebhookInterceptionCache $cache;

	/**
	 * Set up an in-memory ICache behind a mocked ICacheFactory.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->store = [];

		$backend = $this->createMock(ICache::class);
		$backend->method('get')->willReturnCallback(
			fn (string $key) => $this->store[$key] ?? null
		);
		$backend->method('set')->willReturnCallback(
			function (string $key, mixed $value, int $ttl = 0): bool {
				$this->store[$key] = $value;
				return true;
			}
		);
		$backend->method('clear')->willReturnCallback(
			function (string $prefix = ''): bool {
				foreach (array_keys($this->store) as $key) {
					if ($prefix === '' || str_starts_with($key, $prefix) === true) {
						unset($this->store[$key]);
					}
				}

				return true;
			}
		);

		$factory = $this->createMock(ICacheFactory::class);
		// A genuinely distributed backend is configured — the cache arms itself.
		$factory->method('isAvailable')->willReturn(true);
		$factory->method('createDistributed')->willReturn($backend);

		$this->cache = new WebhookInterceptionCache(cacheFactory: $factory);
	}//end setUp()

	/**
	 * A cache miss returns null so the caller knows to compute the flag.
	 *
	 * @return void
	 */
	public function testGetReturnsNullOnMiss(): void {
		$this->assertNull($this->cache->get(eventType: 'object.creating'));
	}//end testGetReturnsNullOnMiss()

	/**
	 * A stored true flag round-trips as boolean true (cache hit).
	 *
	 * @return void
	 */
	public function testSetTrueThenGetReturnsTrue(): void {
		$this->cache->set(eventType: 'object.creating', hasWebhooks: true);

		$this->assertTrue($this->cache->get(eventType: 'object.creating'));
	}//end testSetTrueThenGetReturnsTrue()

	/**
	 * A stored false flag round-trips as boolean false — NOT null — so the
	 * zero-webhook fast path can distinguish "known false" from "unknown".
	 *
	 * @return void
	 */
	public function testSetFalseThenGetReturnsFalse(): void {
		$this->cache->set(eventType: 'object.creating', hasWebhooks: false);

		$this->assertFalse($this->cache->get(eventType: 'object.creating'));
	}//end testSetFalseThenGetReturnsFalse()

	/**
	 * Flags are stored per event type.
	 *
	 * @return void
	 */
	public function testFlagsAreScopedPerEventType(): void {
		$this->cache->set(eventType: 'object.creating', hasWebhooks: true);

		$this->assertTrue($this->cache->get(eventType: 'object.creating'));
		$this->assertNull($this->cache->get(eventType: 'object.updating'));
	}//end testFlagsAreScopedPerEventType()

	/**
	 * invalidate() clears every stored flag (webhook CRUD can change any
	 * event type's answer).
	 *
	 * @return void
	 */
	public function testInvalidateClearsAllFlags(): void {
		$this->cache->set(eventType: 'object.creating', hasWebhooks: true);
		$this->cache->set(eventType: 'object.updating', hasWebhooks: false);

		$this->cache->invalidate();

		$this->assertNull($this->cache->get(eventType: 'object.creating'));
		$this->assertNull($this->cache->get(eventType: 'object.updating'));
	}//end testInvalidateClearsAllFlags()

	// ─── No distributed backend configured ───────────────────────────

	/**
	 * Build a cache whose factory reports NO distributed backend.
	 *
	 * @param ICacheFactory&MockObject $factory Factory mock (isAvailable false pre-set).
	 *
	 * @return WebhookInterceptionCache
	 */
	private function makeUnavailableCache(ICacheFactory&MockObject $factory): WebhookInterceptionCache {
		$factory->method('isAvailable')->willReturn(false);

		return new WebhookInterceptionCache(cacheFactory: $factory);
	}//end makeUnavailableCache()

	/**
	 * Without a distributed backend the cache must never be created:
	 * createDistributed() would fall back to a node-LOCAL cache whose stale
	 * 'false' on one node could bypass an interception webhook created on
	 * another node.
	 *
	 * @return void
	 */
	public function testUnavailableBackendNeverCreatesTheCache(): void {
		$factory = $this->createMock(ICacheFactory::class);
		$factory->expects($this->never())->method('createDistributed');

		$this->makeUnavailableCache($factory);
	}//end testUnavailableBackendNeverCreatesTheCache()

	/**
	 * With the cache disabled, set() is a no-op and get() always reports a
	 * miss — the caller computes the flag from the database on every write.
	 *
	 * @return void
	 */
	public function testUnavailableBackendAlwaysMisses(): void {
		$cache = $this->makeUnavailableCache($this->createMock(ICacheFactory::class));

		$cache->set(eventType: 'object.creating', hasWebhooks: false);

		$this->assertNull($cache->get(eventType: 'object.creating'));
	}//end testUnavailableBackendAlwaysMisses()

	/**
	 * invalidate() stays safe (no-op) with the cache disabled.
	 *
	 * @return void
	 */
	public function testUnavailableBackendInvalidateIsNoOp(): void {
		$cache = $this->makeUnavailableCache($this->createMock(ICacheFactory::class));

		$cache->invalidate();

		$this->assertNull($cache->get(eventType: 'object.creating'));
	}//end testUnavailableBackendInvalidateIsNoOp()
}//end class
