<?php

/**
 * LifecycleActionExecutor tests (lifecycle-action-executor).
 *
 * Exercises the orchestration contract:
 *  - a declared action RUNS and its self-mutation is threaded forward;
 *  - a `condition` that does not hold SKIPS the action;
 *  - a `condition` that holds RUNS the action;
 *  - an unparseable `condition` FAILS LOUDLY;
 *  - a missing handler propagates the registry's fail-loud exception.
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
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Lifecycle;

use OCA\OpenRegister\Lifecycle\Action\SetFieldsAction;
use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use OCA\OpenRegister\Service\Lifecycle\LifecycleActionExecutor;
use OCA\OpenRegister\Service\Lifecycle\LifecycleActionRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @coversDefaultClass \OCA\OpenRegister\Service\Lifecycle\LifecycleActionExecutor
 */
class LifecycleActionExecutorTest extends TestCase
{
    private LifecycleActionRegistry&MockObject $registry;
    private LifecycleActionExecutor $executor;

    protected function setUp(): void
    {
        $this->registry = $this->createMock(LifecycleActionRegistry::class);
        $logger         = $this->createMock(LoggerInterface::class);
        $this->executor = new LifecycleActionExecutor($this->registry, $logger);
    }//end setUp()

    /**
     * A declared action runs and its self-mutation is applied to the payload.
     */
    public function testActionRunsAndMutationIsThreaded(): void
    {
        $this->registry->method('resolve')->with('set-fields')->willReturn(new SetFieldsAction());

        $actions = [
            [
                'action'           => 'set-fields',
                'actionParameters' => ['submittedAt' => 'stamp'],
            ],
        ];

        $result = $this->executor->run(
            actions: $actions,
            objectData: ['status' => 'submitted'],
            previousData: ['status' => 'draft'],
            transition: 'submit'
        );

        $this->assertSame('stamp', $result['submittedAt']);
    }//end testActionRunsAndMutationIsThreaded()

    /**
     * A `condition` that does not hold skips the action — the handler is never
     * resolved.
     */
    public function testConditionFalseSkipsAction(): void
    {
        $this->registry->expects($this->never())->method('resolve');

        $actions = [
            [
                'action'    => 'emit-event',
                'condition' => "@self.settlementMode == 'reimbursable'",
            ],
        ];

        $result = $this->executor->run(
            actions: $actions,
            objectData: ['settlementMode' => 'passthrough'],
            previousData: [],
            transition: 'post'
        );

        $this->assertSame(['settlementMode' => 'passthrough'], $result);
    }//end testConditionFalseSkipsAction()

    /**
     * A `@previous`-scoped condition that holds runs the action.
     */
    public function testPreviousConditionTrueRunsAction(): void
    {
        $handler = $this->createMock(LifecycleActionInterface::class);
        $handler->expects($this->once())->method('execute')->willReturnArgument(0);
        $this->registry->method('resolve')->with('create-offset-move')->willReturn($handler);

        $actions = [
            [
                'action'    => 'create-offset-move',
                'condition' => "@previous.lifecycleState == 'posted'",
            ],
        ];

        $this->executor->run(
            actions: $actions,
            objectData: ['lifecycleState' => 'cancelled'],
            previousData: ['lifecycleState' => 'posted'],
            transition: 'cancel'
        );
    }//end testPreviousConditionTrueRunsAction()

    /**
     * FAIL LOUD: an unparseable condition throws rather than silently skipping.
     */
    public function testUnparseableConditionFailsLoudly(): void
    {
        $actions = [
            [
                'action'    => 'set-fields',
                'condition' => 'amount > 500 and something weird',
            ],
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not a supported expression');

        $this->executor->run(
            actions: $actions,
            objectData: [],
            previousData: [],
            transition: 'submit'
        );
    }//end testUnparseableConditionFailsLoudly()

    /**
     * A missing handler propagates the registry's fail-loud exception out of the
     * executor.
     */
    public function testMissingHandlerPropagates(): void
    {
        $this->registry->method('resolve')->willThrowException(
            new RuntimeException('Lifecycle action "ghost" is declared but no handler is registered.')
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no handler is registered');

        $this->executor->run(
            actions: [['action' => 'ghost']],
            objectData: [],
            previousData: [],
            transition: 'submit'
        );
    }//end testMissingHandlerPropagates()

    /**
     * A malformed action (missing `action` name) fails loudly.
     */
    public function testActionWithoutNameFailsLoudly(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('without an "action" name');

        $this->executor->run(
            actions: [['actionParameters' => ['x' => 1]]],
            objectData: [],
            previousData: [],
            transition: 'submit'
        );
    }//end testActionWithoutNameFailsLoudly()
}//end class
