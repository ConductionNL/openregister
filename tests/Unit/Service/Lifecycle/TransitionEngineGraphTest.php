<?php

/**
 * OpenRegister TransitionEngine graph-mode tests
 *
 * Covers the FK-scoped graph derivation added by the
 * fk-graph-lifecycle-transitions change: forward/adjacent/any candidate sets,
 * terminal-state lockout, static-map precedence, orphan recover-to-start,
 * apply-rejects-non-candidate, and ObjectTransitionedEvent firing on a valid
 * apply. Uses the realistic Omgevingsvergunning seed data from the design.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service\Lifecycle;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @coversDefaultClass \OCA\OpenRegister\Service\Lifecycle\TransitionEngine
 */
class TransitionEngineGraphTest extends TestCase
{
    private const PARENT = '00000000-0000-0000-0000-000000000000';

    private const S1 = '00000000-0000-0000-0000-000000000001';

    private const S2 = '00000000-0000-0000-0000-000000000002';

    private const S3 = '00000000-0000-0000-0000-000000000003';

    private const CASE = '00000000-0000-0000-0000-0000000000aa';

    private ObjectService&MockObject $objectService;

    private SchemaMapper&MockObject $schemaMapper;

    private IEventDispatcher&MockObject $dispatcher;

    private IUserSession&MockObject $userSession;

    private PermissionHandler&MockObject $permission;

    private RegisterMapper&MockObject $registerMapper;

    private IAppConfig&MockObject $appConfig;

    private LoggerInterface&MockObject $logger;

    private TransitionEngine $engine;

    protected function setUp(): void
    {
        $this->objectService = $this->createMock(ObjectService::class);
        $this->schemaMapper   = $this->createMock(SchemaMapper::class);
        $this->dispatcher     = $this->createMock(IEventDispatcher::class);
        $this->userSession    = $this->createMock(IUserSession::class);
        $this->permission     = $this->createMock(PermissionHandler::class);
        $this->permission->method('hasPermission')->willReturn(true);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->appConfig      = $this->createMock(IAppConfig::class);
        $this->logger         = $this->createMock(LoggerInterface::class);

        // The slug contract ships DEFAULT OFF; these graph tests assert the
        // unchanged id-based event scope, so pin the flag to its default.
        $this->appConfig->method('getValueString')
            ->willReturnCallback(
                static function (string $app, string $key, string $default = '') {
                    return $default;
                }
            );

        $this->engine = new TransitionEngine(
            $this->objectService,
            $this->schemaMapper,
            $this->dispatcher,
            $this->userSession,
            $this->permission,
            $this->registerMapper,
            $this->appConfig,
            $this->logger
        );
    }//end setUp()

    /**
     * Build a statusType sibling entity.
     */
    private function statusType(string $uuid, string $name, int $order, bool $final): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setUuid($uuid);
        $entity->setName($name);
        $entity->setObject(
            [
                'name'     => $name,
                'caseType' => self::PARENT,
                'order'    => $order,
                'isFinal'  => $final,
            ]
        );
        return $entity;
    }//end statusType()

    /**
     * All three siblings, deliberately shuffled so the engine's own sort is exercised.
     *
     * @return array<int, ObjectEntity>
     */
    private function siblings(): array
    {
        return [
            $this->statusType(self::S3, 'Afgehandeld', 3, true),
            $this->statusType(self::S1, 'Ontvangen', 1, false),
            $this->statusType(self::S2, 'In behandeling', 2, false),
        ];
    }//end siblings()

    /**
     * Build the case object at a given status.
     */
    private function caseObject(string $status, string $parent=self::PARENT): ObjectEntity
    {
        $case = new ObjectEntity();
        $case->setUuid(self::CASE);
        $case->setSchema('case');
        $case->setRegister('1');
        $case->setObject(['caseType' => $parent, 'status' => $status]);
        return $case;
    }//end caseObject()

    /**
     * Graph annotation with the given move policy.
     *
     * @return array<string, mixed>
     */
    private function graphAnnotation(string $allowedMoves): array
    {
        return [
            'field'   => 'status',
            'initial' => ['from' => 'caseType', 'field' => 'initialStatus'],
            'graph'   => [
                'schema'       => 'statustype',
                'parentField'  => 'caseType',
                'parentFrom'   => 'caseType',
                'orderField'   => 'order',
                'finalField'   => 'isFinal',
                'allowedMoves' => $allowedMoves,
            ],
        ];
    }//end graphAnnotation()

    private function schema(array $annotation): Schema&MockObject
    {
        $schema = $this->createMock(Schema::class);
        $schema->method('getConfiguration')->willReturn(['x-openregister-lifecycle' => $annotation]);
        return $schema;
    }//end schema()

    /**
     * Wire find()/schema/findAll for the given case + annotation.
     */
    private function wire(ObjectEntity $case, array $annotation, ?array $siblings=null): void
    {
        $this->objectService->method('find')->willReturn($case);
        $this->schemaMapper->method('find')->willReturn($this->schema($annotation));
        if ($siblings !== null) {
            $this->objectService->method('findAll')->willReturn($siblings);
        }
    }//end wire()

    public function testForwardOffersOnlyNextStatus(): void
    {
        $this->wire($this->caseObject(self::S1), $this->graphAnnotation('forward'), $this->siblings());

        $actions = $this->engine->availableActions(self::CASE);

        $this->assertCount(1, $actions);
        $this->assertSame('move-to-'.self::S2, $actions[0]['action']);
        $this->assertSame(self::S2, $actions[0]['to']);
        $this->assertSame('In behandeling', $actions[0]['label']);
    }//end testForwardOffersOnlyNextStatus()

    public function testAdjacentOffersPreviousAndNext(): void
    {
        $this->wire($this->caseObject(self::S2), $this->graphAnnotation('adjacent'), $this->siblings());

        $actions = $this->engine->availableActions(self::CASE);
        $tos     = array_column($actions, 'to');

        $this->assertCount(2, $actions);
        $this->assertContains(self::S1, $tos);
        $this->assertContains(self::S3, $tos);
    }//end testAdjacentOffersPreviousAndNext()

    public function testAnyOffersEveryOtherSibling(): void
    {
        $this->wire($this->caseObject(self::S2), $this->graphAnnotation('any'), $this->siblings());

        $actions = $this->engine->availableActions(self::CASE);
        $tos     = array_column($actions, 'to');

        $this->assertCount(2, $actions);
        $this->assertContains(self::S1, $tos);
        $this->assertContains(self::S3, $tos);
        $this->assertNotContains(self::S2, $tos);
    }//end testAnyOffersEveryOtherSibling()

    public function testFinalStateBlocksForwardAndAdjacent(): void
    {
        $this->wire($this->caseObject(self::S3), $this->graphAnnotation('forward'), $this->siblings());

        $this->assertSame([], $this->engine->availableActions(self::CASE));
    }//end testFinalStateBlocksForwardAndAdjacent()

    public function testAnyOverridesTerminalLockout(): void
    {
        $this->wire($this->caseObject(self::S3), $this->graphAnnotation('any'), $this->siblings());

        $actions = $this->engine->availableActions(self::CASE);
        $tos     = array_column($actions, 'to');

        $this->assertCount(2, $actions);
        $this->assertContains(self::S1, $tos);
        $this->assertContains(self::S2, $tos);
    }//end testAnyOverridesTerminalLockout()

    public function testOrphanCurrentValueRecoversToStart(): void
    {
        // Status not among the siblings → offer the first sibling only.
        $this->wire($this->caseObject('deadbeef-0000-0000-0000-000000000000'), $this->graphAnnotation('forward'), $this->siblings());

        $actions = $this->engine->availableActions(self::CASE);

        $this->assertCount(1, $actions);
        $this->assertSame(self::S1, $actions[0]['to']);
    }//end testOrphanCurrentValueRecoversToStart()

    public function testObjectWithoutParentYieldsNoActions(): void
    {
        // Empty parentFrom → no sibling fetch, empty result.
        $this->objectService->expects($this->never())->method('findAll');
        $this->wire($this->caseObject(self::S1, ''), $this->graphAnnotation('forward'));

        $this->assertSame([], $this->engine->availableActions(self::CASE));
    }//end testObjectWithoutParentYieldsNoActions()

    public function testStaticTransitionsTakePrecedenceOverGraph(): void
    {
        // Both a static map and a graph block are declared → static wins,
        // and NO sibling fetch happens.
        $this->objectService->expects($this->never())->method('findAll');

        $annotation = $this->graphAnnotation('any');
        $annotation['initial']     = 'received';
        $annotation['transitions'] = [
            'approve' => ['from' => [self::S1], 'to' => self::S2],
        ];

        $this->wire($this->caseObject(self::S1), $annotation);

        $actions = $this->engine->availableActions(self::CASE);

        $this->assertCount(1, $actions);
        $this->assertSame('approve', $actions[0]['action']);
        $this->assertSame(self::S2, $actions[0]['to']);
    }//end testStaticTransitionsTakePrecedenceOverGraph()

    public function testApplyDerivedTransitionSavesAndDispatchesEvent(): void
    {
        $case = $this->caseObject(self::S1);
        $this->wire($case, $this->graphAnnotation('forward'), $this->siblings());

        $saved = $this->caseObject(self::S2);
        $this->objectService->expects($this->once())
            ->method('saveObject')
            ->willReturn($saved);

        $this->dispatcher->expects($this->once())
            ->method('dispatchTyped')
            ->with(
                $this->callback(
                    function ($event): bool {
                        return $event instanceof ObjectTransitionedEvent
                            && $event->getFrom() === self::S1
                            && $event->getTo() === self::S2
                            && $event->getAction() === 'move-to-'.self::S2;
                    }
                )
            );

        $result = $this->engine->transition(self::CASE, 'move-to-'.self::S2);

        $this->assertSame($saved, $result);
    }//end testApplyDerivedTransitionSavesAndDispatchesEvent()

    public function testApplyRejectsNonCandidateAndDoesNotSave(): void
    {
        $this->wire($this->caseObject(self::S1), $this->graphAnnotation('forward'), $this->siblings());

        // Forward from Ontvangen only allows S2; S3 must be rejected.
        $this->objectService->expects($this->never())->method('saveObject');
        $this->dispatcher->expects($this->never())->method('dispatchTyped');

        $this->expectException(RuntimeException::class);
        $this->engine->transition(self::CASE, 'move-to-'.self::S3);
    }//end testApplyRejectsNonCandidateAndDoesNotSave()

    /**
     * A throwing post-event listener must not fail an already-committed
     * transition. `ObjectTransitionedEvent` is dispatched after `saveObject()`
     * has returned, so propagating the listener's exception would report
     * failure for a write that succeeded.
     *
     * @return void
     */
    public function testThrowingPostEventListenerDoesNotFailACommittedTransition(): void
    {
        $case = $this->caseObject(status: self::S1);
        $this->wire(
            case: $case,
            annotation: $this->graphAnnotation(allowedMoves: 'forward'),
            siblings: $this->siblings()
        );

        $saved = $this->caseObject(status: self::S2);
        $this->objectService->expects($this->once())
            ->method('saveObject')
            ->willReturn($saved);

        $this->dispatcher->method('dispatchTyped')
            ->willThrowException(new \LogicException('listener exploded'));

        $result = $this->engine->transition(objectId: self::CASE, action: 'move-to-'.self::S2);

        // The commit stands and the caller gets the saved object back.
        $this->assertSame(expected: $saved, actual: $result);
    }//end testThrowingPostEventListenerDoesNotFailACommittedTransition()

    /**
     * Swallowing silently would hide a real bug, so the listener failure is
     * logged at ERROR with the exception attached.
     *
     * @return void
     */
    public function testThrowingPostEventListenerIsLoggedAtError(): void
    {
        $case = $this->caseObject(status: self::S1);
        $this->wire(
            case: $case,
            annotation: $this->graphAnnotation(allowedMoves: 'forward'),
            siblings: $this->siblings()
        );

        $this->objectService->method('saveObject')
            ->willReturn($this->caseObject(status: self::S2));

        $boom = new \LogicException('listener exploded');
        $this->dispatcher->method('dispatchTyped')->willThrowException($boom);

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains(string: 'ObjectTransitionedEvent'),
                $this->callback(
                    callback: static function (array $context) use ($boom): bool {
                        return ($context['exception'] ?? null) === $boom
                            && ($context['action'] ?? null) !== null;
                    }
                )
            );

        $this->engine->transition(objectId: self::CASE, action: 'move-to-'.self::S2);
    }//end testThrowingPostEventListenerIsLoggedAtError()
}//end class
