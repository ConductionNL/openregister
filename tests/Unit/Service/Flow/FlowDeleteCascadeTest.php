<?php

/**
 * Deleting a flow takes its execution history with it.
 *
 * `flow_runs`, `flow_run_steps` and `flow_state` all key on a flow (or on one
 * of its runs). The app exposes no endpoint that lists or deletes a run except
 * BY ITS FLOW — so the moment the flow row is gone, every one of those rows is
 * unreachable through any read path that exists. Left behind they are pure
 * landfill: measured on the dev instance before this cascade landed, 493 runs
 * across 80 already-deleted flows, plus 4 orphaned state rows.
 *
 * The ordering matters and is pinned here: `flow_run_steps` keys on the RUN,
 * not the flow, so the run uuids have to be collected before the runs are
 * dropped. Delete the runs first and the steps are stranded with nothing left
 * that can name them.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-engine-unification/specs/flow-execution-history/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\FlowRunStepMapper;
use OCA\OpenRegister\Db\FlowStateMapper;
use OCA\OpenRegister\Service\Flow\FlowRunAdvancer;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Flow\FlowService;
use OCA\OpenRegister\Service\Flow\FlowTriggerIndex;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Cascade behaviour of FlowService::delete().
 */
final class FlowDeleteCascadeTest extends TestCase
{

    /**
     * Build a FlowService whose collaborators are all doubles.
     *
     * @param FlowMapper        $mapper The flow mapper double.
     * @param FlowRunMapper     $runs   The run mapper double.
     * @param FlowRunStepMapper $steps  The step mapper double.
     * @param FlowStateMapper   $state  The state mapper double.
     *
     * @return FlowService The service under test.
     */
    private function serviceWith(
        FlowMapper $mapper,
        FlowRunMapper $runs,
        FlowRunStepMapper $steps,
        FlowStateMapper $state
    ): FlowService {
        // `find()` refuses any flow that does not belong to the ACTIVE
        // organisation, and `belongsTo()` returns false when either side is
        // empty — so a bare container double makes every delete throw
        // DoesNotExistException before the cascade is ever reached, and all
        // three tests would pass for the wrong reason. Resolve a real uuid.
        $organisation = new class {

            /**
             * The active organisation's uuid.
             *
             * @return string The uuid.
             */
            public function getUuid(): string
            {
                return self::ORGANISATION;
            }

            public const ORGANISATION = 'org-under-test';

        };

        $organisationService = new class($organisation)
        {

            /**
             * @param object $organisation The active organisation stub.
             */
            public function __construct(private readonly object $organisation)
            {

            }

            /**
             * The active organisation.
             *
             * @return object The organisation stub.
             */
            public function getActiveOrganisation(): object
            {
                return $this->organisation;

            }

        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($organisationService);

        return new FlowService(
            $mapper,
            $this->createMock(FlowTriggerIndex::class),
            $this->createMock(FlowRunService::class),
            $this->createMock(FlowRunAdvancer::class),
            $runs,
            $steps,
            $state,
            $this->createMock(IUserSession::class),
            $this->createMock(LoggerInterface::class),
            $container
        );

    }//end serviceWith()

    /**
     * A flow mapper double that resolves one uuid and records the delete.
     *
     * @param string $uuid The flow uuid to resolve.
     *
     * @return FlowMapper The configured double.
     */
    private function flowMapperFor(string $uuid): FlowMapper
    {
        $flow = new Flow();
        $flow->setUuid($uuid);
        $flow->setOrganisation('org-under-test');

        $mapper = $this->createMock(FlowMapper::class);
        $mapper->method('findByUuid')->willReturn($flow);
        $mapper->expects($this->once())->method('delete')->willReturn($flow);

        return $mapper;

    }//end flowMapperFor()

    /**
     * Deleting a flow sweeps its runs, each run's steps, and its state.
     *
     * @return void
     */
    public function testDeleteSweepsRunsStepsAndState(): void
    {
        $uuid = 'flow-uuid-1';

        $runs = $this->createMock(FlowRunMapper::class);
        $runs->expects($this->once())
            ->method('deleteByFlow')
            ->with($uuid)
            ->willReturn(['run-a', 'run-b']);

        // One call per run uuid — steps key on the RUN, not the flow.
        $swept = [];
        $steps = $this->createMock(FlowRunStepMapper::class);
        $steps->expects($this->exactly(2))
            ->method('deleteByRun')
            ->willReturnCallback(
                function (string $runUuid) use (&$swept): int {
                    $swept[] = $runUuid;
                    return 1;
                }
            );

        $state = $this->createMock(FlowStateMapper::class);
        $state->expects($this->once())->method('deleteByFlow')->with($uuid);

        $this->serviceWith($this->flowMapperFor($uuid), $runs, $steps, $state)->delete(uuid: $uuid);

        $this->assertSame(['run-a', 'run-b'], $swept, 'every run of the flow should have had its steps swept');

    }//end testDeleteSweepsRunsStepsAndState()

    /**
     * A flow with no runs still sweeps its state and touches no step row.
     *
     * Without this, an early return on "no runs" would skip `flow_state` and
     * leave exactly the orphan the cascade exists to prevent — a flow can carry
     * state without ever having completed a run.
     *
     * @return void
     */
    public function testDeleteWithNoRunsStillSweepsState(): void
    {
        $uuid = 'flow-uuid-2';

        $runs = $this->createMock(FlowRunMapper::class);
        $runs->method('deleteByFlow')->willReturn([]);

        $steps = $this->createMock(FlowRunStepMapper::class);
        $steps->expects($this->never())->method('deleteByRun');

        $state = $this->createMock(FlowStateMapper::class);
        $state->expects($this->once())->method('deleteByFlow')->with($uuid);

        $this->serviceWith($this->flowMapperFor($uuid), $runs, $steps, $state)->delete(uuid: $uuid);

    }//end testDeleteWithNoRunsStillSweepsState()

    /**
     * A sweep that throws does NOT fail the delete.
     *
     * The flow row is already gone by then. Surfacing the error would tell the
     * caller to retry a delete that can only 404 from here on, and the retry
     * would never reach the cascade anyway — so the history would stay orphaned
     * AND the caller would believe nothing was deleted.
     *
     * @return void
     */
    public function testSweepFailureDoesNotFailTheDelete(): void
    {
        $uuid = 'flow-uuid-3';

        $runs = $this->createMock(FlowRunMapper::class);
        $runs->method('deleteByFlow')->willThrowException(new RuntimeException('db gone'));

        $service = $this->serviceWith(
            $this->flowMapperFor($uuid),
            $runs,
            $this->createMock(FlowRunStepMapper::class),
            $this->createMock(FlowStateMapper::class)
        );

        $service->delete(uuid: $uuid);

        // Reaching here IS the assertion: delete() swallowed the sweep failure.
        $this->addToAssertionCount(1);

    }//end testSweepFailureDoesNotFailTheDelete()

}//end class
