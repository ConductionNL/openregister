<?php

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Listener\AggregationThresholdListener;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCA\OpenRegister\Service\Notification\AnnotationNotificationDispatcher;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Verifies the listener fires the dispatcher only when the aggregation
 * value transitions below → above the threshold, and not on subsequent
 * "still above" events.
 */
class AggregationThresholdListenerTest extends TestCase
{
    private SchemaMapper&MockObject $schemaMapper;
    private AggregationRunner&MockObject $runner;
    private AnnotationNotificationDispatcher&MockObject $dispatcher;
    private LoggerInterface&MockObject $logger;
    private ICacheFactory&MockObject $cacheFactory;
    private ICache&MockObject $stateCache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->runner       = $this->createMock(AggregationRunner::class);
        $this->dispatcher   = $this->createMock(AnnotationNotificationDispatcher::class);
        $this->logger       = $this->createMock(LoggerInterface::class);
        $this->cacheFactory = $this->createMock(ICacheFactory::class);
        $this->stateCache   = $this->createMock(ICache::class);
        $this->cacheFactory->method('createDistributed')->willReturn($this->stateCache);
    }

    public function testDispatchesOnTransitionBelowToAbove(): void
    {
        $listener = $this->makeListener();
        [$schema, $object] = $this->fixtures();

        $this->schemaMapper->method('find')->willReturn($schema);
        $this->runner->method('run')->willReturn(['value' => 10]);
        // No prior state recorded.
        $this->stateCache->method('get')->willReturn(null);
        $this->dispatcher->expects($this->once())->method('dispatch');
        $this->stateCache->expects($this->once())->method('set');

        $listener->handle(new ObjectUpdatedEvent($object, $object));
    }

    public function testDoesNotRefireWhenStillAbove(): void
    {
        $listener = $this->makeListener();
        [$schema, $object] = $this->fixtures();

        $this->schemaMapper->method('find')->willReturn($schema);
        $this->runner->method('run')->willReturn(['value' => 10]);
        // Already above on previous run.
        $this->stateCache->method('get')->willReturn('above');
        $this->dispatcher->expects($this->never())->method('dispatch');
        // We still update the cache to keep the TTL fresh.
        $this->stateCache->expects($this->once())->method('set');

        $listener->handle(new ObjectUpdatedEvent($object, $object));
    }

    public function testDoesNotFireBelowThreshold(): void
    {
        $listener = $this->makeListener();
        [$schema, $object] = $this->fixtures();

        $this->schemaMapper->method('find')->willReturn($schema);
        $this->runner->method('run')->willReturn(['value' => 3]);
        $this->stateCache->method('get')->willReturn(null);
        $this->dispatcher->expects($this->never())->method('dispatch');

        $listener->handle(new ObjectUpdatedEvent($object, $object));
    }

    public function testFiresAgainAfterDip(): void
    {
        $listener = $this->makeListener();
        [$schema, $object] = $this->fixtures();

        $this->schemaMapper->method('find')->willReturn($schema);
        $this->runner->method('run')->willReturn(['value' => 99]);
        // Last seen state was below (value dipped before climbing).
        $this->stateCache->method('get')->willReturn('below');
        $this->dispatcher->expects($this->once())->method('dispatch');

        $listener->handle(new ObjectUpdatedEvent($object, $object));
    }

    public function testIgnoresNotificationsWithDifferentTriggerType(): void
    {
        $listener = $this->makeListener();
        $schema   = new Schema();
        $schema->setId(1);
        $schema->setSlug('s');
        $schema->setConfiguration([
            'x-openregister-notifications' => [
                'notATheshold' => [
                    'trigger'    => ['type' => 'updated'],
                    'channels'   => ['nc-notification'],
                    'subject'    => 'unrelated',
                    'recipients' => [['kind' => 'users', 'users' => ['admin']]],
                ],
            ],
        ]);

        $object = new ObjectEntity();
        $object->setUuid('o');
        $object->setSchema('s');
        $object->setRegister('r');

        $this->schemaMapper->method('find')->willReturn($schema);
        $this->runner->expects($this->never())->method('run');
        $this->dispatcher->expects($this->never())->method('dispatch');

        $listener->handle(new ObjectUpdatedEvent($object, $object));
    }

    /**
     * @return array{0: Schema, 1: ObjectEntity}
     */
    private function fixtures(): array
    {
        $schema = new Schema();
        $schema->setId(42);
        $schema->setSlug('test-schema');
        $schema->setConfiguration([
            'x-openregister-notifications' => [
                'overLimit' => [
                    'trigger'    => ['type' => 'threshold', 'aggregation' => 'totalCount', 'op' => 'gt', 'value' => 5],
                    'channels'   => ['nc-notification'],
                    'subject'    => 'Over limit',
                    'recipients' => [['kind' => 'users', 'users' => ['admin']]],
                ],
            ],
        ]);

        $object = new ObjectEntity();
        $object->setUuid('obj-uuid');
        $object->setSchema('test-schema');
        $object->setRegister('test-register');
        return [$schema, $object];
    }

    private function makeListener(): AggregationThresholdListener
    {
        return new AggregationThresholdListener(
            $this->schemaMapper,
            $this->runner,
            $this->dispatcher,
            $this->logger,
            $this->cacheFactory
        );
    }
}
