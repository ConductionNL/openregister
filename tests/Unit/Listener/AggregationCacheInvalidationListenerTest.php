<?php

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Listener\AggregationCacheInvalidationListener;
use OCA\OpenRegister\Service\Aggregation\AggregationCache;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Verifies object-write events cleanly route into AggregationCache::evictForSchema.
 *
 * If this fails, aggregations would surface stale numbers for up to 60s
 * after every write — a correctness bug the cache TTL papers over but
 * shouldn't have to.
 */
class AggregationCacheInvalidationListenerTest extends TestCase
{
    private AggregationCache&MockObject $cache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cache = $this->createMock(AggregationCache::class);
    }

    public function testCreatedEventEvictsByRegisterAndSchemaSlug(): void
    {
        $object = $this->object('register-A', 'schema-A');
        $this->cache->expects($this->once())
            ->method('evictForSchema')
            ->with('register-A', 'schema-A');

        (new AggregationCacheInvalidationListener($this->cache))
            ->handle(new ObjectCreatedEvent($object));
    }

    public function testUpdatedEventEvicts(): void
    {
        $object = $this->object('reg', 'sch');
        $this->cache->expects($this->once())->method('evictForSchema')->with('reg', 'sch');

        (new AggregationCacheInvalidationListener($this->cache))
            ->handle(new ObjectUpdatedEvent($object, $object));
    }

    public function testDeletedEventEvicts(): void
    {
        $object = $this->object('reg', 'sch');
        $this->cache->expects($this->once())->method('evictForSchema')->with('reg', 'sch');

        (new AggregationCacheInvalidationListener($this->cache))
            ->handle(new ObjectDeletedEvent($object));
    }

    public function testTransitionedEventEvicts(): void
    {
        $object = $this->object('reg', 'sch');
        $this->cache->expects($this->once())->method('evictForSchema')->with('reg', 'sch');

        (new AggregationCacheInvalidationListener($this->cache))
            ->handle(new ObjectTransitionedEvent($object, 'close', 'open', 'closed', null, 'reg', 'sch'));
    }

    public function testUnrelatedEventIsIgnored(): void
    {
        $event = $this->createMock(Event::class);
        $this->cache->expects($this->never())->method('evictForSchema');

        (new AggregationCacheInvalidationListener($this->cache))->handle($event);
    }

    private function object(string $register, string $schema): ObjectEntity
    {
        $object = new ObjectEntity();
        $object->setUuid('uuid-1');
        $object->setRegister($register);
        $object->setSchema($schema);
        return $object;
    }
}
