<?php

/**
 * LifecycleActionListener tests (lifecycle-action-executor).
 *
 * Proves the issue #427 fix at the save-path boundary:
 *  - a LIST-FORM transition (a plain ObjectUpdatingEvent that moves the
 *    lifecycle field, WITHOUT going through TransitionEngine) now runs the
 *    declared actions and stamps the field onto the payload — the exact
 *    LeaseContract-class bug where actions never fired for list-form edits;
 *  - a transition with no declared actions is left untouched;
 *  - a rejected/blocked transition (propagation stopped) does not run actions;
 *  - a declared action naming a missing handler FAILS LOUDLY (propagates).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Lifecycle\Action\SetFieldsAction;
use OCA\OpenRegister\Listener\LifecycleActionListener;
use OCA\OpenRegister\Service\Lifecycle\LifecycleActionExecutor;
use OCA\OpenRegister\Service\Lifecycle\LifecycleActionRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @coversDefaultClass \OCA\OpenRegister\Listener\LifecycleActionListener
 */
class LifecycleActionListenerTest extends TestCase
{
    private SchemaMapper&MockObject $schemaMapper;
    private LifecycleActionRegistry&MockObject $registry;
    private LifecycleActionListener $listener;

    protected function setUp(): void
    {
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->registry     = $this->createMock(LifecycleActionRegistry::class);
        $logger             = $this->createMock(LoggerInterface::class);

        // Real executor over a mocked registry — exercises the whole executor
        // path (condition eval + threading) while letting the test control
        // handler resolution.
        $executor = new LifecycleActionExecutor($this->registry, $logger);

        $this->listener = new LifecycleActionListener(
            $this->schemaMapper,
            $executor,
            $logger
        );
    }//end setUp()

    /**
     * Schema declaring a `activate` transition (draft → active) whose `actions[]`
     * stamps `activatedAt` — the shape shillinq's LeaseContract declares.
     *
     * @param array<int, mixed> $actions The transition's declared actions.
     */
    private function leaseSchema(array $actions): Schema
    {
        $schema = new Schema();
        $schema->setId(7);
        $schema->setSlug('lease-contract');
        $schema->setConfiguration(
            [
                'x-openregister-lifecycle' => [
                    'field'       => 'lifecycleState',
                    'transitions' => [
                        'activate' => [
                            'from'    => ['draft'],
                            'to'      => 'active',
                            'actions' => $actions,
                        ],
                    ],
                ],
            ]
        );

        $this->schemaMapper->method('find')->willReturn($schema);
        return $schema;
    }//end leaseSchema()

    /**
     * Build the pre/post entities for a list-form transition draft → active.
     *
     * @return array{0: ObjectEntity, 1: ObjectEntity}
     */
    private function transitionEntities(): array
    {
        $old = new ObjectEntity();
        $old->setSchema('lease-contract');
        $old->setUuid('lease-1');
        $old->setObject(['lifecycleState' => 'draft']);

        $new = new ObjectEntity();
        $new->setSchema('lease-contract');
        $new->setUuid('lease-1');
        $new->setObject(['lifecycleState' => 'active']);

        return [$old, $new];
    }//end transitionEntities()

    /**
     * THE list-form bug fix: a plain ObjectUpdatingEvent (no TransitionEngine)
     * that moves the lifecycle field runs the declared action and stamps the
     * field onto the persisted payload.
     */
    public function testListFormTransitionRunsDeclaredActionAndStampsField(): void
    {
        $this->leaseSchema(
            [
                [
                    'action'           => 'set-fields',
                    'actionParameters' => ['activatedAt' => '@now'],
                ],
            ]
        );

        // The built-in handler resolves — as it would in production.
        $this->registry->method('resolve')->with('set-fields')->willReturn(new SetFieldsAction());

        [$old, $new] = $this->transitionEntities();
        $event       = new ObjectUpdatingEvent(newObject: $new, oldObject: $old);

        $this->listener->handle($event);

        // The action ran: activatedAt is now stamped on the object the save
        // will persist — proving actions fire for a list-form transition.
        $data = $new->getObject();
        $this->assertArrayHasKey('activatedAt', $data);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
            (string) $data['activatedAt']
        );
        $this->assertSame('active', $data['lifecycleState']);
    }//end testListFormTransitionRunsDeclaredActionAndStampsField()

    /**
     * A transition that declares no actions leaves the payload untouched and
     * never resolves a handler.
     */
    public function testTransitionWithoutActionsIsNoop(): void
    {
        $this->leaseSchema([]);
        $this->registry->expects($this->never())->method('resolve');

        [$old, $new] = $this->transitionEntities();
        $event       = new ObjectUpdatingEvent(newObject: $new, oldObject: $old);

        $this->listener->handle($event);

        $data = $new->getObject();
        $this->assertArrayNotHasKey('activatedAt', $data);
        $this->assertSame('active', $data['lifecycleState']);
    }//end testTransitionWithoutActionsIsNoop()

    /**
     * A blocked transition (a prior listener stopped propagation) does not run
     * actions — the transition is not happening.
     */
    public function testBlockedTransitionDoesNotRunActions(): void
    {
        $this->registry->expects($this->never())->method('resolve');

        [$old, $new] = $this->transitionEntities();
        $event       = new ObjectUpdatingEvent(newObject: $new, oldObject: $old);
        $event->stopPropagation();

        $this->listener->handle($event);

        // schemaMapper is never even consulted once propagation is stopped.
        $this->assertArrayNotHasKey('activatedAt', $new->getObject());
    }//end testBlockedTransitionDoesNotRunActions()

    /**
     * A create (no old object) is not a transition — actions do not run.
     */
    public function testCreateWithoutOldObjectIsNoop(): void
    {
        $this->registry->expects($this->never())->method('resolve');

        $new = new ObjectEntity();
        $new->setSchema('lease-contract');
        $new->setUuid('lease-1');
        $new->setObject(['lifecycleState' => 'active']);

        $event = new ObjectUpdatingEvent(newObject: $new, oldObject: null);
        $this->listener->handle($event);

        $this->assertArrayNotHasKey('activatedAt', $new->getObject());
    }//end testCreateWithoutOldObjectIsNoop()

    /**
     * FAIL LOUD: a declared action naming a missing handler throws out of the
     * listener (aborting the save), rather than silently no-oping.
     */
    public function testMissingHandlerFailsLoudly(): void
    {
        $this->leaseSchema([['action' => 'phantom-materialiser']]);

        $this->registry->method('resolve')->willThrowException(
            new RuntimeException('Lifecycle action "phantom-materialiser" is declared but no handler is registered.')
        );

        [$old, $new] = $this->transitionEntities();
        $event       = new ObjectUpdatingEvent(newObject: $new, oldObject: $old);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no handler is registered');

        $this->listener->handle($event);
    }//end testMissingHandlerFailsLoudly()
}//end class
