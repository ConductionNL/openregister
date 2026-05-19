<?php

/**
 * RateLimiterTest
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/notificatie-engine/tasks.md#task-14
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Notification;

use OCA\OpenRegister\Service\Notification\RateLimiter;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for RateLimiter token-bucket implementation.
 */
class RateLimiterTest extends TestCase
{

    private ICacheFactory&MockObject $cacheFactory;
    private IAppConfig&MockObject $appConfig;
    private LoggerInterface&MockObject $logger;
    private ICache&MockObject $cache;

    protected function setUp(): void
    {
        $this->cacheFactory = $this->createMock(ICacheFactory::class);
        $this->appConfig    = $this->createMock(IAppConfig::class);
        $this->logger       = $this->createMock(LoggerInterface::class);
        $this->cache        = $this->createMock(ICache::class);

        $this->cacheFactory->method('isAvailable')->willReturn(true);
        $this->cacheFactory->method('createDistributed')->willReturn($this->cache);

        $this->appConfig->method('getValueString')->willReturnCallback(
            function (string $app, string $key, string $default): string {
                return match ($key) {
                    'notification_rate_limit_enabled' => 'true',
                    'notification_rate_limit_default_bucket_size' => '10',
                    'notification_rate_limit_default_refill_seconds' => '60',
                    default => $default,
                };
            }
        );
    }

    private function makeLimiter(): RateLimiter
    {
        return new RateLimiter(
            cacheFactory: $this->cacheFactory,
            appConfig: $this->appConfig,
            logger: $this->logger
        );
    }

    /**
     * Full bucket allows up to bucketSize dispatches.
     */
    public function testBucketDrainAllowsDispatch(): void
    {
        // Full bucket (10 tokens).
        $this->cache->method('get')->willReturn(null);
        $this->cache->method('set')->willReturn(null);

        $limiter = $this->makeLimiter();
        self::assertTrue($limiter->allow(ruleId: 'rule1', recipient: 'alice'));
    }

    /**
     * Empty bucket (0 tokens) blocks dispatch.
     */
    public function testEmptyBucketBlocksDispatch(): void
    {
        $this->cache->method('get')->willReturn(['tokens' => 0, 'lastRefill' => time()]);
        $this->cache->method('set')->willReturn(null);

        $limiter = $this->makeLimiter();
        self::assertFalse($limiter->allow(ruleId: 'rule1', recipient: 'alice'));
    }

    /**
     * Elapsed time causes token refill.
     */
    public function testElapsedTimeCausesRefill(): void
    {
        // 0 tokens, lastRefill 120 seconds ago → refill 2 tokens (1 per 60s).
        $this->cache->method('get')->willReturn(['tokens' => 0, 'lastRefill' => time() - 120]);
        $this->cache->method('set')->willReturn(null);

        $limiter = $this->makeLimiter();
        self::assertTrue($limiter->allow(ruleId: 'rule1', recipient: 'alice'));
    }

    /**
     * Per-(rule, recipient) isolation: different keys don't share state.
     */
    public function testPerRuleRecipientIsolation(): void
    {
        $callCount = 0;
        $this->cache->method('get')->willReturnCallback(
            function () use (&$callCount) {
                $callCount++;
                return null;
            }
        );
        $this->cache->method('set')->willReturn(null);

        $limiter = $this->makeLimiter();
        $limiter->allow(ruleId: 'rule1', recipient: 'alice');
        $limiter->allow(ruleId: 'rule2', recipient: 'alice');
        $limiter->allow(ruleId: 'rule1', recipient: 'bob');

        // Each call hits the cache independently.
        self::assertSame(3, $callCount);
    }

    /**
     * Kill switch disables rate limiting.
     */
    public function testKillSwitchDisablesLimiting(): void
    {
        $this->appConfig->method('getValueString')->willReturnCallback(
            function (string $app, string $key, string $default): string {
                if ($key === 'notification_rate_limit_enabled') {
                    return 'false';
                }

                return $default;
            }
        );

        // Even empty bucket: kill switch means always allow.
        $this->cache->method('get')->willReturn(['tokens' => 0, 'lastRefill' => time()]);

        $limiter = $this->makeLimiter();
        self::assertTrue($limiter->allow(ruleId: 'rule1', recipient: 'alice'));
    }

    /**
     * App-config defaults are honoured.
     */
    public function testAppConfigDefaultsHonoured(): void
    {
        $this->appConfig->method('getValueString')->willReturnCallback(
            function (string $app, string $key, string $default): string {
                return match ($key) {
                    'notification_rate_limit_enabled'             => 'true',
                    'notification_rate_limit_default_bucket_size' => '3',
                    'notification_rate_limit_default_refill_seconds' => '30',
                    default => $default,
                };
            }
        );

        // 3 tokens available.
        $this->cache->method('get')->willReturn(null);
        $this->cache->method('set')->willReturn(null);

        $limiter = $this->makeLimiter();
        self::assertTrue($limiter->allow(ruleId: 'rule1', recipient: 'alice'));
    }

    /**
     * Empty ruleId or recipient fails open (never blocks).
     */
    public function testEmptyInputsFailOpen(): void
    {
        $limiter = $this->makeLimiter();
        self::assertTrue($limiter->allow(ruleId: '', recipient: 'alice'));
        self::assertTrue($limiter->allow(ruleId: 'rule1', recipient: ''));
    }

    /**
     * Cache failure fails open with info log.
     */
    public function testCacheFailureFailsOpen(): void
    {
        $this->cache->method('get')->willThrowException(new \RuntimeException('Cache down'));
        $this->logger->expects(self::once())->method('info');

        $limiter = $this->makeLimiter();
        self::assertTrue($limiter->allow(ruleId: 'rule1', recipient: 'alice'));
    }

    /**
     * Rate-limited dispatch logs at info, not warning.
     */
    public function testRateLimitedLogsAtInfoNotWarning(): void
    {
        $this->cache->method('get')->willReturn(['tokens' => 0, 'lastRefill' => time()]);
        $this->cache->method('set')->willReturn(null);
        $this->logger->expects(self::once())->method('info');
        $this->logger->expects(self::never())->method('warning');

        $limiter = $this->makeLimiter();
        $limiter->allow(ruleId: 'rule1', recipient: 'alice');
    }
}
