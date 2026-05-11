<?php

/**
 * OpenRegister CalculatedChangeListenerTest
 *
 * Unit tests for the CalculatedChangeListener.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Listener\CalculatedChangeListener;
use OCA\OpenRegister\Service\Notification\AnnotationNotificationDispatcher;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * CalculatedChangeListener unit tests.
 *
 * Covers the four required scenarios:
 *   (a) first save below threshold doesn't fire
 *   (b) above → below transition fires
 *   (c) below → still-below doesn't re-fire (debounce)
 *   (d) condition + previously must both hold
 */
class CalculatedChangeListenerTest extends TestCase
{

    private SchemaMapper&MockObject $schemaMapper;
    private AnnotationNotificationDispatcher&MockObject $dispatcher;
    private LoggerInterface&MockObject $logger;
    private ICacheFactory&MockObject $cacheFactory;
    private ICache&MockObject $stateCache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->dispatcher   = $this->createMock(AnnotationNotificationDispatcher::class);
        $this->logger       = $this->createMock(LoggerInterface::class);
        $this->cacheFactory = $this->createMock(ICacheFactory::class);
        $this->stateCache   = $this->createMock(ICache::class);
        $this->cacheFactory->method('createDistributed')->willReturn($this->stateCache);
    }

    // -----------------------------------------------------------------------
    // (a) First save whose value is ABOVE the threshold (condition not met)
    //     should not fire the notification.
    // -----------------------------------------------------------------------

    /**
     * @test
     */
    public function testFirstSaveAboveThresholdDoesNotFire(): void
    {
        $listener = $this->makeListener();
        [$schema, $newObj, $oldObj] = $this->fixtures(newCoverage: 0.90, oldCoverage: 0.90);

        $this->schemaMapper->method('find')->willReturn($schema);
        // State cache has no entry — first ever save.
        $this->stateCache->method('get')->willReturn(null);

        // condition is { lt: 0.85 }, value is 0.90 → condition NOT satisfied.
        $this->dispatcher->expects($this->never())->method('dispatch');

        $listener->handle(new ObjectUpdatedEvent($newObj, $oldObj));
    }

    // -----------------------------------------------------------------------
    // (b) above → below transition: condition satisfied, previously satisfied
    //     → notification MUST fire.
    // -----------------------------------------------------------------------

    /**
     * @test
     */
    public function testAboveToBelowTransitionFires(): void
    {
        $listener = $this->makeListener();
        // New value is 0.80 (below 0.85, satisfies "lt: 0.85").
        // Old value is 0.90 (above 0.85, satisfies "gte: 0.85").
        [$schema, $newObj, $oldObj] = $this->fixtures(newCoverage: 0.80, oldCoverage: 0.90);

        $this->schemaMapper->method('find')->willReturn($schema);
        $this->dispatcher->expects($this->once())->method('dispatch');

        $listener->handle(new ObjectUpdatedEvent($newObj, $oldObj));
    }

    // -----------------------------------------------------------------------
    // (c) below → still-below: condition satisfied but previously NOT
    //     satisfied (old value also below the "gte: 0.85" boundary)
    //     → no re-fire (debounce).
    // -----------------------------------------------------------------------

    /**
     * @test
     */
    public function testBelowToStillBelowDoesNotReFire(): void
    {
        $listener = $this->makeListener();
        // Both new and old values are below 0.85.
        // "previously" { "gte": 0.85 } is NOT satisfied by oldVal = 0.70.
        [$schema, $newObj, $oldObj] = $this->fixtures(newCoverage: 0.80, oldCoverage: 0.70);

        $this->schemaMapper->method('find')->willReturn($schema);
        $this->dispatcher->expects($this->never())->method('dispatch');

        $listener->handle(new ObjectUpdatedEvent($newObj, $oldObj));
    }

    // -----------------------------------------------------------------------
    // (d) condition AND previously must both hold simultaneously.
    //     Only `condition` holds (new below) but `previously` fails
    //     (old also below) → no fire.
    // -----------------------------------------------------------------------

    /**
     * @test
     */
    public function testConditionHoldsButPreviouslyFailsDoesNotFire(): void
    {
        $listener = $this->makeListener();
        // New coverage 0.80: condition { lt: 0.85 } ✓
        // Old coverage 0.70: previously { gte: 0.85 } ✗ (0.70 < 0.85)
        [$schema, $newObj, $oldObj] = $this->fixtures(newCoverage: 0.80, oldCoverage: 0.70);

        $this->schemaMapper->method('find')->willReturn($schema);
        $this->dispatcher->expects($this->never())->method('dispatch');

        $listener->handle(new ObjectUpdatedEvent($newObj, $oldObj));
    }

    // -----------------------------------------------------------------------
    // Extra: without explicit `previously`, cache-based debounce applies.
    //        First crossing fires; subsequent still-below saves don't.
    // -----------------------------------------------------------------------

    /**
     * @test
     */
    public function testNoPreviouslyBlock_firstCrossingFires(): void
    {
        $listener = $this->makeListener(withPreviously: false);
        // New value below threshold, no prior state in cache.
        [$schema, $newObj, $oldObj] = $this->fixtures(newCoverage: 0.80, oldCoverage: 0.90, withPreviously: false);

        $this->schemaMapper->method('find')->willReturn($schema);
        $this->stateCache->method('get')->willReturn(null); // no prior state
        $this->dispatcher->expects($this->once())->method('dispatch');
        $this->stateCache->expects($this->once())->method('set');

        $listener->handle(new ObjectUpdatedEvent($newObj, $oldObj));
    }

    /**
     * @test
     */
    public function testNoPreviouslyBlock_stillSatisfiedDoesNotReFire(): void
    {
        $listener = $this->makeListener(withPreviously: false);
        [$schema, $newObj, $oldObj] = $this->fixtures(newCoverage: 0.80, oldCoverage: 0.70, withPreviously: false);

        $this->schemaMapper->method('find')->willReturn($schema);
        // Cache already shows 'satisfied' from a previous save.
        $this->stateCache->method('get')->willReturn('satisfied');
        $this->dispatcher->expects($this->never())->method('dispatch');

        $listener->handle(new ObjectUpdatedEvent($newObj, $oldObj));
    }

    // -----------------------------------------------------------------------
    // Guard: listener ignores non-calculatedChange trigger types.
    // -----------------------------------------------------------------------

    /**
     * @test
     */
    public function testIgnoresNonCalculatedChangeTriggers(): void
    {
        $listener = $this->makeListener();
        $schema   = new Schema();
        $schema->setId(99);
        $schema->setSlug('other-schema');
        $schema->setConfiguration([
            'x-openregister-notifications' => [
                'onUpdate' => [
                    'trigger'    => ['type' => 'updated'],
                    'field'      => 'coveragePercent',
                    'condition'  => ['lt' => 0.85],
                    'channels'   => ['nc-notification'],
                    'subject'    => 'Unrelated',
                    'recipients' => [['kind' => 'users', 'users' => ['admin']]],
                ],
            ],
        ]);

        $newObj = $this->makeObject(coverage: 0.80, schemaSlug: 'other-schema');
        $oldObj = $this->makeObject(coverage: 0.90, schemaSlug: 'other-schema');

        $this->schemaMapper->method('find')->willReturn($schema);
        $this->dispatcher->expects($this->never())->method('dispatch');

        $listener->handle(new ObjectUpdatedEvent($newObj, $oldObj));
    }

    /**
     * @test
     */
    public function testStringTriggerShorthandIsRecognised(): void
    {
        $listener = $this->makeListener();
        $schema   = new Schema();
        $schema->setId(77);
        $schema->setSlug('shorthand-schema');
        $schema->setConfiguration([
            'x-openregister-notifications' => [
                'alertOnDrop' => [
                    // String shorthand.
                    'trigger'    => 'calculatedChange',
                    'field'      => 'coveragePercent',
                    'condition'  => ['lt' => 0.85],
                    'previously' => ['gte' => 0.85],
                    'channels'   => ['nc-notification'],
                    'subject'    => 'Coverage dropped',
                    'recipients' => [['kind' => 'users', 'users' => ['admin']]],
                ],
            ],
        ]);

        $newObj = $this->makeObject(coverage: 0.80, schemaSlug: 'shorthand-schema');
        $oldObj = $this->makeObject(coverage: 0.90, schemaSlug: 'shorthand-schema');

        $this->schemaMapper->method('find')->willReturn($schema);
        $this->dispatcher->expects($this->once())->method('dispatch');

        $listener->handle(new ObjectUpdatedEvent($newObj, $oldObj));
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Build the default fixtures: a schema with a calculatedChange notification
     * and two object snapshots (new + old).
     *
     * @param float $newCoverage  Value of `coveragePercent` in the new object.
     * @param float $oldCoverage  Value of `coveragePercent` in the old object.
     * @param bool  $withPreviously Whether to include a `previously` block.
     *
     * @return array{0: Schema, 1: ObjectEntity, 2: ObjectEntity}
     */
    private function fixtures(
        float $newCoverage,
        float $oldCoverage,
        bool $withPreviously=true
    ): array {
        $spec = [
            'trigger'    => ['type' => 'calculatedChange'],
            'field'      => 'coveragePercent',
            'condition'  => ['lt' => 0.85],
            'channels'   => ['nc-notification'],
            'subject'    => 'Coverage dropped below 85%',
            'recipients' => [['kind' => 'users', 'users' => ['officer']]],
        ];

        if ($withPreviously === true) {
            $spec['previously'] = ['gte' => 0.85];
        }

        $schema = new Schema();
        $schema->setId(42);
        $schema->setSlug('regulation');
        $schema->setConfiguration([
            'x-openregister-notifications' => [
                'officerAlertOnCoverageDrop' => $spec,
            ],
        ]);

        $newObj = $this->makeObject(coverage: $newCoverage, schemaSlug: 'regulation');
        $oldObj = $this->makeObject(coverage: $oldCoverage, schemaSlug: 'regulation');

        return [$schema, $newObj, $oldObj];
    }

    /**
     * Build an ObjectEntity with a given coveragePercent field value.
     *
     * @param float  $coverage   Value of the coveragePercent field.
     * @param string $schemaSlug Schema slug to set on the entity.
     *
     * @return ObjectEntity
     */
    private function makeObject(float $coverage, string $schemaSlug): ObjectEntity
    {
        $obj = new ObjectEntity();
        $obj->setUuid('test-uuid');
        $obj->setSchema($schemaSlug);
        $obj->setRegister('test-register');
        $obj->setObject(['coveragePercent' => $coverage]);
        return $obj;
    }

    /**
     * Instantiate a CalculatedChangeListener with mock collaborators.
     *
     * @param bool $withPreviously Unused — kept for API symmetry with fixtures().
     *
     * @return CalculatedChangeListener
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private function makeListener(bool $withPreviously=true): CalculatedChangeListener
    {
        return new CalculatedChangeListener(
            $this->schemaMapper,
            $this->dispatcher,
            $this->logger,
            $this->cacheFactory
        );
    }
}//end class
