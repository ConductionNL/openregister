<?php

/**
 * RateLimiterService Unit Tests.
 *
 * Covers task 1.18: the fail-closed contract (no cache backend → not operational) plus the
 * fixed-window and distinct-key-budget limiter shapes.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use OCA\OpenRegister\Service\Configuration\RateLimiterService;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for `RateLimiterService`.
 *
 * @package OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @covers \OCA\OpenRegister\Service\Configuration\RateLimiterService
 *
 * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-18
 */
class RateLimiterServiceTest extends TestCase
{
    /**
     * @return void
     */
    public function testIsOperationalWhenDistributedCacheAvailable(): void
    {
        $service = new RateLimiterService(cacheFactory: $this->buildFactory(distributed: true, local: false));
        $this->assertTrue($service->isOperational());
    }//end testIsOperationalWhenDistributedCacheAvailable()

    /**
     * @return void
     */
    public function testIsOperationalWhenOnlyLocalCacheAvailable(): void
    {
        $service = new RateLimiterService(cacheFactory: $this->buildFactory(distributed: false, local: true));
        $this->assertTrue($service->isOperational());
    }//end testIsOperationalWhenOnlyLocalCacheAvailable()

    /**
     * @return void
     */
    public function testIsNotOperationalWhenNoCacheBackend(): void
    {
        $service = new RateLimiterService(cacheFactory: $this->buildFactory(distributed: false, local: false));
        $this->assertFalse($service->isOperational());
    }//end testIsNotOperationalWhenNoCacheBackend()

    /**
     * @return void
     */
    public function testFixedWindowAllowsThenBlocks(): void
    {
        $service = new RateLimiterService(cacheFactory: $this->buildFactory(distributed: true, local: false));

        // First check — slot is free.
        $this->assertNull($service->checkFixedWindow(bucketKey: 'sub:alice', windowSeconds: 60));

        // Consume it.
        $service->markFixedWindow(bucketKey: 'sub:alice', windowSeconds: 60);

        // Second check — slot occupied, returns a positive retry-after ≤ window.
        $retryAfter = $service->checkFixedWindow(bucketKey: 'sub:alice', windowSeconds: 60);
        $this->assertNotNull($retryAfter);
        $this->assertGreaterThanOrEqual(1, $retryAfter);
        $this->assertLessThanOrEqual(60, $retryAfter);
    }//end testFixedWindowAllowsThenBlocks()

    /**
     * @return void
     */
    public function testDistinctKeyBudgetAllowsUpToMaxThenBlocks(): void
    {
        $service = new RateLimiterService(cacheFactory: $this->buildFactory(distributed: true, local: false));

        for ($i = 0; $i < 5; $i++) {
            $this->assertNull(
                $service->consumeDistinctKeyBudget(bucketKey: 'getmiss:alice', distinctKey: 'k-'.$i, maxKeys: 5, windowSeconds: 300),
                'distinct key '.$i.' should pass'
            );
        }

        // 6th distinct key exceeds the budget.
        $retryAfter = $service->consumeDistinctKeyBudget(bucketKey: 'getmiss:alice', distinctKey: 'k-6', maxKeys: 5, windowSeconds: 300);
        $this->assertNotNull($retryAfter);
        $this->assertGreaterThanOrEqual(1, $retryAfter);
    }//end testDistinctKeyBudgetAllowsUpToMaxThenBlocks()

    /**
     * @return void
     */
    public function testDistinctKeyBudgetRepeatKeysAreFree(): void
    {
        $service = new RateLimiterService(cacheFactory: $this->buildFactory(distributed: true, local: false));

        for ($i = 0; $i < 20; $i++) {
            $this->assertNull(
                $service->consumeDistinctKeyBudget(bucketKey: 'getmiss:alice', distinctKey: 'same-key', maxKeys: 5, windowSeconds: 300),
                'repeats of the same key never exhaust the budget'
            );
        }
    }//end testDistinctKeyBudgetRepeatKeysAreFree()

    /**
     * Build a mocked ICacheFactory with an in-memory cache + the requested availability flags.
     *
     * @param bool $distributed Value returned by ICacheFactory::isAvailable().
     * @param bool $local       Value returned by ICacheFactory::isLocalCacheAvailable().
     *
     * @return ICacheFactory
     */
    private function buildFactory(bool $distributed, bool $local): ICacheFactory
    {
        $state = new \ArrayObject();
        $cache = $this->createMock(ICache::class);
        $cache->method('get')->willReturnCallback(
            fn (string $key) => ($state->offsetExists($key) ? $state->offsetGet($key) : null)
        );
        $cache->method('set')->willReturnCallback(
            function (string $key, $value, int $ttl = 0) use ($state): bool {
                $state->offsetSet($key, $value);
                return true;
            }
        );

        $factory = $this->createMock(ICacheFactory::class);
        $factory->method('createDistributed')->willReturn($cache);
        $factory->method('isAvailable')->willReturn($distributed);
        $factory->method('isLocalCacheAvailable')->willReturn($local);
        return $factory;
    }//end buildFactory()
}//end class
