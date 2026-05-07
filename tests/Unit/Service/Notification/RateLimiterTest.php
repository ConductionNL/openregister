<?php

declare(strict_types=1);

namespace Unit\Service\Notification;

use OCA\OpenRegister\Service\Notification\RateLimiter;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Verifies the token-bucket rate limiter behaviour. Time is mocked
 * so the tests are deterministic — no sleep(), no timing flakes.
 */
class RateLimiterTest extends TestCase
{
    private IAppConfig&MockObject $appConfig;
    private LoggerInterface&MockObject $logger;
    private ICacheFactory&MockObject $cacheFactory;
    private InMemoryCache $cache;
    private int $now = 1000000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->appConfig    = $this->createMock(IAppConfig::class);
        $this->logger       = $this->createMock(LoggerInterface::class);
        $this->cache        = new InMemoryCache();
        $this->cacheFactory = $this->createMock(ICacheFactory::class);
        $this->cacheFactory->method('createDistributed')->willReturn($this->cache);
    }

    public function testAllowsUpToBucketSizeThenDrops(): void
    {
        $this->appConfigDefaults();
        $limiter = $this->makeLimiter();

        // Bucket size is 10 (default). Override per-rule down to 3
        // so the test doesn't have to spam 11 calls.
        $override = ['bucketSize' => 3, 'refillSecondsPerToken' => 60];

        $this->assertTrue($limiter->tryConsume('rule-a', 'alice', $override));
        $this->assertTrue($limiter->tryConsume('rule-a', 'alice', $override));
        $this->assertTrue($limiter->tryConsume('rule-a', 'alice', $override));
        $this->assertFalse($limiter->tryConsume('rule-a', 'alice', $override));
    }

    public function testRefillsOneTokenAfterRefillInterval(): void
    {
        $this->appConfigDefaults();
        $limiter = $this->makeLimiter();
        $override = ['bucketSize' => 2, 'refillSecondsPerToken' => 60];

        // Drain the bucket.
        $this->assertTrue($limiter->tryConsume('rule-b', 'bob', $override));
        $this->assertTrue($limiter->tryConsume('rule-b', 'bob', $override));
        $this->assertFalse($limiter->tryConsume('rule-b', 'bob', $override));

        // Advance time by exactly one refill interval — one token returns.
        $this->now += 60;
        $this->assertTrue($limiter->tryConsume('rule-b', 'bob', $override));
        $this->assertFalse($limiter->tryConsume('rule-b', 'bob', $override));
    }

    public function testIsolatesPerRuleAndPerRecipient(): void
    {
        $this->appConfigDefaults();
        $limiter = $this->makeLimiter();
        $override = ['bucketSize' => 1, 'refillSecondsPerToken' => 3600];

        // Drain alice's bucket on rule-a.
        $this->assertTrue($limiter->tryConsume('rule-a', 'alice', $override));
        $this->assertFalse($limiter->tryConsume('rule-a', 'alice', $override));

        // Bob on the same rule still has a full bucket.
        $this->assertTrue($limiter->tryConsume('rule-a', 'bob', $override));
        // Alice on a different rule still has a full bucket.
        $this->assertTrue($limiter->tryConsume('rule-b', 'alice', $override));
    }

    public function testKillSwitchAlwaysAllows(): void
    {
        $this->appConfig->method('getValueString')->willReturn('false');
        $this->appConfig->method('getValueInt')->willReturnArgument(2);

        $limiter = $this->makeLimiter();
        // Even with bucket size 1, the kill switch should let every
        // call through.
        $override = ['bucketSize' => 1, 'refillSecondsPerToken' => 3600];
        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($limiter->tryConsume('rule-a', 'alice', $override));
        }
    }

    public function testAppConfigDefaultsApply(): void
    {
        $this->appConfig->method('getValueString')
            ->with('openregister', 'notification_rate_limit_enabled', 'true')
            ->willReturn('true');
        $this->appConfig->method('getValueInt')
            ->willReturnCallback(static function (string $app, string $key, int $default): int {
                if ($key === 'notification_rate_limit_default_bucket_size') {
                    return 2;
                }
                if ($key === 'notification_rate_limit_default_refill_seconds') {
                    return 30;
                }
                return $default;
            });

        $limiter = $this->makeLimiter();
        // No per-rule override -> uses the operator's defaults: bucket=2.
        $this->assertTrue($limiter->tryConsume('rule-c', 'carol'));
        $this->assertTrue($limiter->tryConsume('rule-c', 'carol'));
        $this->assertFalse($limiter->tryConsume('rule-c', 'carol'));

        // Refill interval is 30s, so one token returns after 30s.
        $this->now += 30;
        $this->assertTrue($limiter->tryConsume('rule-c', 'carol'));
    }

    public function testEmptyRuleOrRecipientFailsOpen(): void
    {
        $this->appConfigDefaults();
        $limiter = $this->makeLimiter();
        $override = ['bucketSize' => 1, 'refillSecondsPerToken' => 3600];

        // Spam without ever consuming a token from any real bucket.
        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($limiter->tryConsume('', 'alice', $override));
            $this->assertTrue($limiter->tryConsume('rule', '', $override));
        }
    }

    public function testCacheReadFailureFailsOpen(): void
    {
        $this->appConfigDefaults();
        $cache = $this->createMock(ICache::class);
        $cache->method('get')->willThrowException(new \RuntimeException('redis down'));
        $cacheFactory = $this->createMock(ICacheFactory::class);
        $cacheFactory->method('createDistributed')->willReturn($cache);

        $limiter = new RateLimiter(
            $cacheFactory,
            $this->appConfig,
            $this->logger,
            fn (): int => $this->now
        );

        // Bucket size 1, but each call fails open because cache is broken.
        $override = ['bucketSize' => 1, 'refillSecondsPerToken' => 3600];
        $this->assertTrue($limiter->tryConsume('rule', 'alice', $override));
        $this->assertTrue($limiter->tryConsume('rule', 'alice', $override));
    }

    public function testDropEmitsInfoNotWarning(): void
    {
        $this->appConfigDefaults();
        $this->logger->expects($this->never())->method('warning');
        $this->logger->expects($this->atLeastOnce())->method('info');

        $limiter = $this->makeLimiter();
        $override = ['bucketSize' => 1, 'refillSecondsPerToken' => 3600];
        $limiter->tryConsume('rule', 'alice', $override);
        $limiter->tryConsume('rule', 'alice', $override); // dropped
    }

    private function appConfigDefaults(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturnCallback(static fn (string $app, string $key, string $default): string => $default);
        $this->appConfig->method('getValueInt')
            ->willReturnCallback(static fn (string $app, string $key, int $default): int => $default);
    }

    private function makeLimiter(): RateLimiter
    {
        return new RateLimiter(
            $this->cacheFactory,
            $this->appConfig,
            $this->logger,
            fn (): int => $this->now
        );
    }
}

/**
 * Minimal in-memory ICache for tests. Implements only the methods
 * the RateLimiter touches (get/set/hasKey/remove/clear) — others
 * throw so a test using them surfaces the gap.
 */
class InMemoryCache implements ICache
{
    /** @var array<string, mixed> */
    public array $store = [];

    public function get($key)
    {
        return $this->store[$key] ?? null;
    }

    public function set($key, $value, $ttl = 0): bool
    {
        $this->store[$key] = $value;
        return true;
    }

    public function hasKey($key): bool
    {
        return array_key_exists($key, $this->store);
    }

    public function remove($key): bool
    {
        unset($this->store[$key]);
        return true;
    }

    public function clear($prefix = ''): bool
    {
        $this->store = [];
        return true;
    }

    public static function isAvailable(): bool
    {
        return true;
    }
}
