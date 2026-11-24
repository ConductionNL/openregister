<?php

/**
 * FlowLocator::scheduledFlows() when the flow store cannot list, and for a
 * flow that carries no node graph at all.
 *
 * @category Test
 * @package  Unit\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapperInterface;
use OCA\OpenRegister\Db\FlowTriggerMapperInterface;
use OCA\OpenRegister\Db\FlowVersionMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Flow\FlowLocator;
use OCA\OpenRegister\Service\Flow\FlowLocatorStores;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The scheduler never sees an exception from listing, and a graphless flow has the server's timezone.
 */
class FlowLocatorScheduleEdgeTest extends TestCase {

	/**
	 * The flow store.
	 *
	 * @var FlowMapperInterface&MockObject
	 */
	private FlowMapperInterface&MockObject $mapper;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->mapper = $this->createMock(FlowMapperInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The locator under test.
	 *
	 * @return FlowLocator
	 */
	private function locator(): FlowLocator {
		return new FlowLocator(
			new FlowLocatorStores(
				$this->mapper,
				$this->createMock(FlowTriggerMapperInterface::class),
				$this->createMock(FlowVersionMapperInterface::class)
			),
			$this->createMock(ObjectOperationsInterface::class),
			$this->logger
		);
	}//end locator()

	/**
	 * A store that cannot list scheduled flows yields none, with a warning.
	 *
	 * @return void
	 */
	public function testAStoreThatCannotListYieldsNoCandidates(): void {
		$this->mapper->method('findScheduled')->willThrowException(new RuntimeException('table missing'));
		$this->logger->expects($this->once())
			->method('warning')
			->with('[FlowLocator] Could not list scheduled flows: table missing');

		$this->assertSame([], $this->locator()->scheduledFlows());
	}//end testAStoreThatCannotListYieldsNoCandidates()

	/**
	 * A dispatchable flow without a node graph runs its cron in the server's timezone.
	 *
	 * @return void
	 */
	public function testAFlowWithoutNodesHasNoScheduleTimezone(): void {
		$flow = new Flow();
		$flow->setUuid('s1');
		$flow->setEnabled(true);
		$flow->setOwner('alice');
		$flow->setTrigger('schedule');
		$flow->setCron('*/5 * * * *');
		$this->mapper->method('findScheduled')->willReturn([$flow]);
		$this->logger->expects($this->never())->method('warning');

		$candidates = $this->locator()->scheduledFlows();

		$this->assertSame(
			[
				'id' => 's1',
				'enabled' => true,
				'trigger' => 'schedule',
				'cron' => '*/5 * * * *',
				'owner' => 'alice',
				'since' => '',
				'timezone' => '',
			],
			$candidates[0]
		);
	}//end testAFlowWithoutNodesHasNoScheduleTimezone()
}//end class
