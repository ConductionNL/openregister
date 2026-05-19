<?php

/**
 * NotificationCoalescerTest
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/notificatie-engine/tasks.md#task-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Notification;

use OCA\OpenRegister\Service\Notification\NotificationCoalescer;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for NotificationCoalescer debounce-window implementation.
 */
class NotificationCoalescerTest extends TestCase
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

        $this->appConfig->method('getValueString')->willReturn('true');
    }

    private function makeCoalescer(): NotificationCoalescer
    {
        return new NotificationCoalescer(
            cacheFactory: $this->cacheFactory,
            appConfig: $this->appConfig,
            logger: $this->logger
        );
    }

    private function ruleSpec(int $windowSeconds=60, ?int $maxEvents=null): array
    {
        $coalesce = ['windowSeconds' => $windowSeconds];
        if ($maxEvents !== null) {
            $coalesce['maxEvents'] = $maxEvents;
        }

        return ['coalesce' => $coalesce];
    }

    /**
     * First event opens window and fires dispatch.
     */
    public function testFirstEventFiresAndOpensWindow(): void
    {
        $this->cache->method('get')->willReturn(null);
        $this->cache->expects(self::once())->method('set');

        $coalescer = $this->makeCoalescer();
        self::assertTrue($coalescer->shouldDispatch(ruleId: 'r1', recipient: 'alice', ruleSpec: $this->ruleSpec()));
    }

    /**
     * Subsequent event inside open window is silenced.
     */
    public function testSubsequentEventSilencedInsideWindow(): void
    {
        $this->cache->method('get')->willReturn(['openedAt' => time(), 'count' => 1]);
        $this->cache->method('set')->willReturn(null);

        $coalescer = $this->makeCoalescer();
        self::assertFalse($coalescer->shouldDispatch(ruleId: 'r1', recipient: 'alice', ruleSpec: $this->ruleSpec(windowSeconds: 3600)));
    }

    /**
     * Window expiry opens fresh window and fires.
     */
    public function testWindowExpiryOpensFreshWindow(): void
    {
        // Window was opened 120s ago, windowSeconds = 60.
        $this->cache->method('get')->willReturn(['openedAt' => time() - 120, 'count' => 5]);
        $this->cache->method('set')->willReturn(null);

        $coalescer = $this->makeCoalescer();
        self::assertTrue($coalescer->shouldDispatch(ruleId: 'r1', recipient: 'alice', ruleSpec: $this->ruleSpec(windowSeconds: 60)));
    }

    /**
     * maxEvents forces early flush.
     */
    public function testMaxEventsForcesFlushandFires(): void
    {
        // count = 9, maxEvents = 10 → next event (count becomes 10) triggers early flush.
        $this->cache->method('get')->willReturn(['openedAt' => time(), 'count' => 9]);
        $this->cache->expects(self::once())->method('remove');

        $coalescer = $this->makeCoalescer();
        self::assertTrue($coalescer->shouldDispatch(ruleId: 'r1', recipient: 'alice', ruleSpec: $this->ruleSpec(windowSeconds: 3600, maxEvents: 10)));
    }

    /**
     * Per-(rule, recipient) isolation.
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

        $coalescer = $this->makeCoalescer();
        $coalescer->shouldDispatch(ruleId: 'r1', recipient: 'alice', ruleSpec: $this->ruleSpec());
        $coalescer->shouldDispatch(ruleId: 'r2', recipient: 'alice', ruleSpec: $this->ruleSpec());
        $coalescer->shouldDispatch(ruleId: 'r1', recipient: 'bob', ruleSpec: $this->ruleSpec());

        self::assertSame(3, $callCount);
    }

    /**
     * Null/absent coalesce config means no coalescing.
     */
    public function testNullConfigMeansNoCoalescing(): void
    {
        $coalescer = $this->makeCoalescer();
        self::assertTrue($coalescer->shouldDispatch(ruleId: 'r1', recipient: 'alice', ruleSpec: []));
    }

    /**
     * Kill switch always allows.
     */
    public function testKillSwitchAlwaysAllows(): void
    {
        $this->appConfig->method('getValueString')->willReturn('false');
        $this->cache->method('get')->willReturn(['openedAt' => time(), 'count' => 99]);

        $coalescer = $this->makeCoalescer();
        self::assertTrue($coalescer->shouldDispatch(ruleId: 'r1', recipient: 'alice', ruleSpec: $this->ruleSpec()));
    }

    /**
     * Empty rule or recipient fails open.
     */
    public function testEmptyInputsFailOpen(): void
    {
        $coalescer = $this->makeCoalescer();
        self::assertTrue($coalescer->shouldDispatch(ruleId: '', recipient: 'alice', ruleSpec: $this->ruleSpec()));
        self::assertTrue($coalescer->shouldDispatch(ruleId: 'r1', recipient: '', ruleSpec: $this->ruleSpec()));
    }

    /**
     * Cache failure fails open.
     */
    public function testCacheFailureFailsOpen(): void
    {
        $this->cache->method('get')->willThrowException(new \RuntimeException('Cache down'));
        $this->logger->expects(self::once())->method('info');

        $coalescer = $this->makeCoalescer();
        self::assertTrue($coalescer->shouldDispatch(ruleId: 'r1', recipient: 'alice', ruleSpec: $this->ruleSpec()));
    }

    /**
     * Silenced dispatches log at info, not warning.
     */
    public function testSilencedDispatchLogsAtInfo(): void
    {
        $this->cache->method('get')->willReturn(['openedAt' => time(), 'count' => 1]);
        $this->cache->method('set')->willReturn(null);
        $this->logger->expects(self::once())->method('info');
        $this->logger->expects(self::never())->method('warning');

        $coalescer = $this->makeCoalescer();
        $coalescer->shouldDispatch(ruleId: 'r1', recipient: 'alice', ruleSpec: $this->ruleSpec(windowSeconds: 3600));
    }

    /**
     * inspect() returns current state.
     */
    public function testInspectReturnsCurrentState(): void
    {
        $state = ['openedAt' => 12345, 'count' => 3];
        $this->cache->method('get')->willReturn($state);

        $coalescer = $this->makeCoalescer();
        self::assertSame($state, $coalescer->inspect(ruleId: 'r1', recipient: 'alice'));
    }

    /**
     * inspect() returns null when no active window.
     */
    public function testInspectReturnsNullWhenNoWindow(): void
    {
        $this->cache->method('get')->willReturn(null);

        $coalescer = $this->makeCoalescer();
        self::assertNull($coalescer->inspect(ruleId: 'r1', recipient: 'alice'));
    }
}
