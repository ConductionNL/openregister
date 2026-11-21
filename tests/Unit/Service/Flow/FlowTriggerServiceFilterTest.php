<?php

/**
 * Firing queues a run only for flows whose published trigger accepts the transition or the changed fields.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-trigger-transitions-and-changed-fields/specs/flow-engine/spec.md
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Service\Flow\FlowExecution;
use OCA\OpenRegister\Service\Flow\FlowLocator;
use OCA\OpenRegister\Service\Flow\FlowPublishedGraphInterface;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Flow\FlowTriggerService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowTriggerService
 * @uses \OCA\OpenRegister\Service\Flow\FlowTriggerFilter
 * @uses \OCA\OpenRegister\Service\Flow\FlowExecution
 * @uses \OCA\OpenRegister\Service\Flow\FlowTrigger
 * @uses \OCA\OpenRegister\Service\Flow\FlowRunQueueOptions
 * @uses \OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef
 * @uses \OCA\OpenRegister\Db\FlowRun
 */
final class FlowTriggerServiceFilterTest extends TestCase {

	/**
	 * Queued flow ids.
	 *
	 * @var array<int, string>
	 */
	private array $queued = [];

	/**
	 * A service over one wired flow whose published graph holds this trigger config.
	 *
	 * @param array<string, mixed>|null $config The trigger config, or null for a flow with no published graph.
	 * @param bool                      $reader Whether a graph reader is wired at all.
	 *
	 * @return FlowTriggerService
	 */
	private function service(?array $config, bool $reader = true): FlowTriggerService {
		$locator = $this->createMock(FlowLocator::class);
		$locator->method('flowsForEvent')->willReturn(['flow-1']);

		$runner = $this->createMock(FlowRunService::class);
		$runner->method('queueRun')->willReturnCallback(
			function (string $flowId): FlowRun {
				$this->queued[] = $flowId;
				$run = new FlowRun();
				$run->setUuid('run-' . $flowId);
				$run->setFlowId($flowId);

				return $run;
			}
		);

		$published = null;
		if ($reader === true) {
			$published = $this->createMock(FlowPublishedGraphInterface::class);
			$graph = null;
			if ($config !== null) {
				$graph = ['nodes' => [['id' => 't', 'type' => 'openregister.trigger-object', 'config' => $config]], 'edges' => []];
			}

			$published->method('graphOf')->with('flow-1')->willReturn($graph);
		}

		return new FlowTriggerService(new FlowExecution($locator, $runner, new NullLogger()), $published);
	}//end service()

	/**
	 * Fire one event on aanvraag.
	 *
	 * @param FlowTriggerService   $service The service.
	 * @param string               $event   The event.
	 * @param array<string, mixed> $context The context.
	 *
	 * @return int The count queued.
	 */
	private function fire(FlowTriggerService $service, string $event, array $context): int {
		return $service->fire(event: $event, subject: ['uuid' => 'u1', 'register' => 'zaken', 'schema' => 'aanvraag'], user: 'alice', context: $context);
	}//end fire()

	/**
	 * A transition to closed queues one run; one back to open queues none.
	 *
	 * @return void
	 */
	public function testOnlyAMatchingTransitionQueuesARun(): void {
		$config = ['event' => 'object.transitioned', 'register' => 'zaken', 'schema' => 'aanvraag', 'transition' => ['to' => ['closed']]];

		$this->assertSame(1, $this->fire($this->service($config), 'object.transitioned', ['action' => 'close', 'from' => 'open', 'to' => 'closed']));
		$this->assertSame(0, $this->fire($this->service($config), 'object.transitioned', ['action' => 'reopen', 'from' => 'closed', 'to' => 'open']));
		$this->assertSame(['flow-1'], $this->queued);
	}//end testOnlyAMatchingTransitionQueuesARun()

	/**
	 * An update that changes a watched field queues a run; the flow's own write of another field does not.
	 *
	 * @return void
	 */
	public function testOnlyAWatchedFieldChangeQueuesARun(): void {
		$config = ['event' => 'object.updated', 'register' => 'zaken', 'schema' => 'melding', 'changedFields' => ['omschrijving']];

		$this->assertSame(1, $this->fire($this->service($config), 'object.updated', ['changedFields' => ['omschrijving']]));
		$this->assertSame(0, $this->fire($this->service($config), 'object.updated', ['changedFields' => ['categorie']]));
		$this->assertSame(0, $this->fire($this->service($config), 'object.updated', []));
		$this->assertSame(['flow-1'], $this->queued);
	}//end testOnlyAWatchedFieldChangeQueuesARun()

	/**
	 * Without a graph reader, without a published graph, or on an unfiltered event, the flow queues as before.
	 *
	 * @return void
	 */
	public function testUnfilteredPathsQueueAsBefore(): void {
		$watching = ['event' => 'object.updated', 'register' => 'zaken', 'schema' => 'melding', 'changedFields' => ['omschrijving']];

		$this->assertSame(1, $this->fire($this->service($watching, reader: false), 'object.updated', []));
		$this->assertSame(1, $this->fire($this->service(null), 'object.updated', []));
		$this->assertSame(1, $this->fire($this->service($watching), 'object.created', []));
		$this->assertSame(['flow-1', 'flow-1', 'flow-1'], $this->queued);
	}//end testUnfilteredPathsQueueAsBefore()
}//end class
