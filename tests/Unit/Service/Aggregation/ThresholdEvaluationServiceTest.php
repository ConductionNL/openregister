<?php

declare(strict_types=1);

namespace Unit\Service\Aggregation;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCA\OpenRegister\Service\Aggregation\ThresholdEvaluationService;
use OCA\OpenRegister\Service\Notification\AnnotationNotificationDispatcher;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Rising-edge threshold semantics, extracted verbatim from the listener:
 * fire on below → above transitions only, refresh state, tolerate failures.
 */
class ThresholdEvaluationServiceTest extends TestCase
{
    private AggregationRunner&MockObject $runner;
    private AnnotationNotificationDispatcher&MockObject $dispatcher;
    private LoggerInterface&MockObject $logger;
    private ICacheFactory&MockObject $cacheFactory;
    private ICache&MockObject $stateCache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runner       = $this->createMock(AggregationRunner::class);
        $this->dispatcher   = $this->createMock(AnnotationNotificationDispatcher::class);
        $this->logger       = $this->createMock(LoggerInterface::class);
        $this->cacheFactory = $this->createMock(ICacheFactory::class);
        $this->stateCache   = $this->createMock(ICache::class);
        $this->cacheFactory->method('createDistributed')->willReturn($this->stateCache);
    }

    private function makeService(): ThresholdEvaluationService
    {
        return new ThresholdEvaluationService(
            aggregationRunner: $this->runner,
            dispatcher: $this->dispatcher,
            logger: $this->logger,
            cacheFactory: $this->cacheFactory,
        );
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
                'tooMany' => [
                    'trigger'    => [
                        'type'        => 'threshold',
                        'aggregation' => 'openCount',
                        'op'          => 'gt',
                        'value'       => 5,
                    ],
                    'channels'   => ['nc-notification'],
                    'subject'    => 'Too many open items',
                    'recipients' => [['kind' => 'users', 'users' => ['admin']]],
                ],
            ],
        ]);

        $object = new ObjectEntity();
        $object->setUuid('obj-1');
        $object->setSchema('test-schema');
        $object->setRegister('test-register');

        return [$schema, $object];
    }

    public function testDispatchesOnTransitionBelowToAbove(): void
    {
        [$schema, $object] = $this->fixtures();
        $this->runner->method('run')->willReturn(['value' => 10]);
        $this->stateCache->method('get')->willReturn(null);
        $this->dispatcher->expects($this->once())->method('dispatch');
        $this->stateCache->expects($this->once())->method('set');

        $this->makeService()->evaluateSchema(schema: $schema, object: $object);
    }

    public function testDoesNotRefireWhenStillAbove(): void
    {
        [$schema, $object] = $this->fixtures();
        $this->runner->method('run')->willReturn(['value' => 10]);
        $this->stateCache->method('get')->willReturn('above');
        $this->dispatcher->expects($this->never())->method('dispatch');
        // The cache is still refreshed to keep the TTL alive.
        $this->stateCache->expects($this->once())->method('set');

        $this->makeService()->evaluateSchema(schema: $schema, object: $object);
    }

    public function testDoesNotFireBelowThreshold(): void
    {
        [$schema, $object] = $this->fixtures();
        $this->runner->method('run')->willReturn(['value' => 3]);
        $this->stateCache->method('get')->willReturn(null);
        $this->dispatcher->expects($this->never())->method('dispatch');

        $this->makeService()->evaluateSchema(schema: $schema, object: $object);
    }

    public function testFiresAgainAfterDip(): void
    {
        [$schema, $object] = $this->fixtures();
        $this->runner->method('run')->willReturn(['value' => 99]);
        $this->stateCache->method('get')->willReturn('below');
        $this->dispatcher->expects($this->once())->method('dispatch');

        $this->makeService()->evaluateSchema(schema: $schema, object: $object);
    }

    public function testIgnoresNotificationsWithDifferentTriggerType(): void
    {
        $schema = new Schema();
        $schema->setId(1);
        $schema->setSlug('s');
        $schema->setConfiguration([
            'x-openregister-notifications' => [
                'notAThreshold' => [
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

        $this->runner->expects($this->never())->method('run');
        $this->dispatcher->expects($this->never())->method('dispatch');

        $this->makeService()->evaluateSchema(schema: $schema, object: $object);
    }

    public function testEvaluationFailureIsLoggedAndDoesNotEscalate(): void
    {
        [$schema, $object] = $this->fixtures();
        $this->runner->method('run')->willThrowException(new \RuntimeException('agg failed'));
        $this->logger->expects($this->once())->method('warning');
        $this->dispatcher->expects($this->never())->method('dispatch');

        $this->makeService()->evaluateSchema(schema: $schema, object: $object);
    }

    public function testHasThresholdNotificationsDetectsDeclaration(): void
    {
        [$schema] = $this->fixtures();
        $service  = $this->makeService();

        $this->assertTrue($service->hasThresholdNotifications($schema));

        $plainSchema = new Schema();
        $plainSchema->setId(2);
        $plainSchema->setSlug('plain');
        $plainSchema->setConfiguration([
            'x-openregister-notifications' => [
                'onCreate' => ['trigger' => ['type' => 'created']],
            ],
        ]);
        $this->assertFalse($service->hasThresholdNotifications($plainSchema));

        $emptySchema = new Schema();
        $emptySchema->setId(3);
        $emptySchema->setSlug('empty');
        $this->assertFalse($service->hasThresholdNotifications($emptySchema));
    }
}
