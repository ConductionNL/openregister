<?php

declare(strict_types=1);

namespace Unit\Service\Notification;

use OCA\OpenRegister\Service\Notification\NotificationCoalescer;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Verifies the per-(rule, recipient) debounce coalescer. Time is
 * mocked so the tests are deterministic — no sleep(), no flakes.
 *
 * @spec openspec/changes/notificatie-engine/tasks.md
 */
class NotificationCoalescerTest extends TestCase
{
    private IAppConfig&MockObject $appConfig;
    private LoggerInterface&MockObject $logger;
    private ICacheFactory&MockObject $cacheFactory;
    private InMemoryCoalesceCache $cache;
    private int $now = 2000000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->appConfig    = $this->createMock(IAppConfig::class);
        $this->logger       = $this->createMock(LoggerInterface::class);
        $this->cache        = new InMemoryCoalesceCache();
        $this->cacheFactory = $this->createMock(ICacheFactory::class);
        $this->cacheFactory->method('createDistributed')->willReturn($this->cache);
    }

    public function testFirstEventOpensWindowAndDispatches(): void
    {
        $this->appConfigDefaults();
        $coalescer = $this->makeCoalescer();
        $coalesce  = ['windowSeconds' => 60];

        $this->assertTrue($coalescer->shouldDispatch('rule-a', 'alice', $coalesce));
    }

    public function testSubsequentEventsInWindowAreSilenced(): void
    {
        $this->appConfigDefaults();
        $coalescer = $this->makeCoalescer();
        $coalesce  = ['windowSeconds' => 60];

        // First event opens the window + fires.
        $this->assertTrue($coalescer->shouldDispatch('rule-a', 'alice', $coalesce));

        // Subsequent events in the same window are silenced.
        $this->assertFalse($coalescer->shouldDispatch('rule-a', 'alice', $coalesce));
        $this->assertFalse($coalescer->shouldDispatch('rule-a', 'alice', $coalesce));
        $this->assertFalse($coalescer->shouldDispatch('rule-a', 'alice', $coalesce));
    }

    public function testNextEventAfterWindowOpensFreshWindow(): void
    {
        $this->appConfigDefaults();
        $coalescer = $this->makeCoalescer();
        $coalesce  = ['windowSeconds' => 60];

        $this->assertTrue($coalescer->shouldDispatch('rule-a', 'alice', $coalesce));
        $this->assertFalse($coalescer->shouldDispatch('rule-a', 'alice', $coalesce));

        // Advance past the window — next event opens a fresh one.
        $this->now += 61;
        $this->assertTrue($coalescer->shouldDispatch('rule-a', 'alice', $coalesce));
    }

    public function testMaxEventsForcesEarlyFlush(): void
    {
        $this->appConfigDefaults();
        $coalescer = $this->makeCoalescer();
        // Window is wide; maxEvents=3 forces a flush after 3 silenced events.
        $coalesce = ['windowSeconds' => 3600, 'maxEvents' => 3];

        $this->assertTrue($coalescer->shouldDispatch('rule-a', 'alice', $coalesce));
        $this->assertFalse($coalescer->shouldDispatch('rule-a', 'alice', $coalesce)); // count=2
        $this->assertTrue($coalescer->shouldDispatch('rule-a', 'alice', $coalesce));  // count would be 3 — flush.
    }

    public function testIsolatesPerRuleAndPerRecipient(): void
    {
        $this->appConfigDefaults();
        $coalescer = $this->makeCoalescer();
        $coalesce  = ['windowSeconds' => 60];

        $this->assertTrue($coalescer->shouldDispatch('rule-a', 'alice', $coalesce));
        $this->assertFalse($coalescer->shouldDispatch('rule-a', 'alice', $coalesce));

        // Bob on the same rule still has a fresh window.
        $this->assertTrue($coalescer->shouldDispatch('rule-a', 'bob', $coalesce));
        // Alice on a different rule still has a fresh window.
        $this->assertTrue($coalescer->shouldDispatch('rule-b', 'alice', $coalesce));
    }

    public function testNoConfigBlockMeansNoCoalescing(): void
    {
        $this->appConfigDefaults();
        $coalescer = $this->makeCoalescer();

        // Null config = the rule didn't opt into coalescing.
        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($coalescer->shouldDispatch('rule', 'alice', null));
        }
    }

    public function testKillSwitchAlwaysAllows(): void
    {
        $this->appConfig->method('getValueString')->willReturn('false');

        $coalescer = $this->makeCoalescer();
        $coalesce  = ['windowSeconds' => 60];

        // With the kill switch on, every event fires regardless.
        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($coalescer->shouldDispatch('rule-a', 'alice', $coalesce));
        }
    }

    public function testEmptyRuleOrRecipientFailsOpen(): void
    {
        $this->appConfigDefaults();
        $coalescer = $this->makeCoalescer();
        $coalesce  = ['windowSeconds' => 60];

        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($coalescer->shouldDispatch('', 'alice', $coalesce));
            $this->assertTrue($coalescer->shouldDispatch('rule', '', $coalesce));
        }
    }

    public function testInspectReturnsCurrentWindowState(): void
    {
        $this->appConfigDefaults();
        $coalescer = $this->makeCoalescer();
        $coalesce  = ['windowSeconds' => 60];

        $coalescer->shouldDispatch('rule-a', 'alice', $coalesce);
        $coalescer->shouldDispatch('rule-a', 'alice', $coalesce);
        $coalescer->shouldDispatch('rule-a', 'alice', $coalesce);

        $state = $coalescer->inspect('rule-a', 'alice');
        $this->assertIsArray($state);
        $this->assertSame(3, $state['count']);
        $this->assertSame($this->now, $state['opened']);
    }

    public function testInspectReturnsNullWhenNoState(): void
    {
        $this->appConfigDefaults();
        $coalescer = $this->makeCoalescer();

        $this->assertNull($coalescer->inspect('rule-a', 'alice'));
    }

    public function testCacheReadFailureFailsOpen(): void
    {
        $this->appConfigDefaults();
        $cache = $this->createMock(ICache::class);
        $cache->method('get')->willThrowException(new \RuntimeException('redis down'));
        $cacheFactory = $this->createMock(ICacheFactory::class);
        $cacheFactory->method('createDistributed')->willReturn($cache);

        $coalescer = new NotificationCoalescer(
            $cacheFactory,
            $this->appConfig,
            $this->logger,
            fn (): int => $this->now
        );

        $coalesce = ['windowSeconds' => 60];
        $this->assertTrue($coalescer->shouldDispatch('rule', 'alice', $coalesce));
        $this->assertTrue($coalescer->shouldDispatch('rule', 'alice', $coalesce));
    }

    public function testSilencedDispatchEmitsInfoNotWarning(): void
    {
        $this->appConfigDefaults();
        $this->logger->expects($this->never())->method('warning');
        $this->logger->expects($this->atLeastOnce())->method('info');

        $coalescer = $this->makeCoalescer();
        $coalesce  = ['windowSeconds' => 60];
        $coalescer->shouldDispatch('rule', 'alice', $coalesce); // first
        $coalescer->shouldDispatch('rule', 'alice', $coalesce); // silenced
    }

    private function appConfigDefaults(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturnCallback(static fn (string $app, string $key, string $default): string => $default);
    }

    private function makeCoalescer(): NotificationCoalescer
    {
        return new NotificationCoalescer(
            $this->cacheFactory,
            $this->appConfig,
            $this->logger,
            fn (): int => $this->now
        );
    }
}

/**
 * Minimal in-memory ICache for tests. Implements only the methods
 * NotificationCoalescer touches.
 */
class InMemoryCoalesceCache implements ICache
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
