<?php

declare(strict_types=1);

namespace Unit\EventListener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\EventListener\AggregationCacheInvalidationListener;
use OCA\OpenRegister\Service\Aggregation\AggregationCache;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for AggregationCacheInvalidationListener.
 *
 * @covers \OCA\OpenRegister\EventListener\AggregationCacheInvalidationListener
 */
class AggregationCacheInvalidationListenerTest extends TestCase
{

    private AggregationCacheInvalidationListener $listener;
    private AggregationCache&MockObject $cache;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cache    = $this->createMock(AggregationCache::class);
        $this->logger   = $this->createMock(LoggerInterface::class);
        $this->listener = new AggregationCacheInvalidationListener(
            cache: $this->cache,
            logger: $this->logger
        );
    }

    public function testObjectCreatedEventEvictsCache(): void
    {
        $this->cache->expects($this->once())->method('evict');

        $object = $this->createMock(ObjectEntity::class);
        $this->listener->handle(event: new ObjectCreatedEvent(object: $object));
    }

    public function testObjectUpdatedEventEvictsCache(): void
    {
        $this->cache->expects($this->once())->method('evict');

        $object = $this->createMock(ObjectEntity::class);
        $this->listener->handle(event: new ObjectUpdatedEvent(newObject: $object));
    }

    public function testObjectDeletedEventEvictsCache(): void
    {
        $this->cache->expects($this->once())->method('evict');

        $object = $this->createMock(ObjectEntity::class);
        $this->listener->handle(event: new ObjectDeletedEvent(object: $object));
    }

    public function testUnrelatedEventIsIgnored(): void
    {
        $this->cache->expects($this->never())->method('evict');

        $unrelated = new class extends Event {};
        $this->listener->handle(event: $unrelated);
    }

    public function testAllThreeWriteEventsAreHandled(): void
    {
        $this->cache->expects($this->exactly(3))->method('evict');

        $object = $this->createMock(ObjectEntity::class);
        $this->listener->handle(event: new ObjectCreatedEvent(object: $object));
        $this->listener->handle(event: new ObjectUpdatedEvent(newObject: $object));
        $this->listener->handle(event: new ObjectDeletedEvent(object: $object));
    }

}//end class
