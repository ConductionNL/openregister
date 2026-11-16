<?php

/**
 * Deprecating a published flow retires its version and its trigger rows in one transaction; a failed draft or deprecation rolls back and rethrows.
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
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use DateTime;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Db\FlowVersion;
use OCA\OpenRegister\Db\FlowVersionMapper;
use OCA\OpenRegister\Service\Flow\FlowDefinitionPin;
use OCA\OpenRegister\Service\Flow\FlowLifecycleGuard;
use OCA\OpenRegister\Service\Flow\FlowLifecycleRefused;
use OCA\OpenRegister\Service\Flow\FlowSemanticVersion;
use OCA\OpenRegister\Service\Flow\FlowTriggerIndex;
use OCA\OpenRegister\Service\Flow\FlowVersionGraphs;
use OCA\OpenRegister\Service\Flow\FlowVersionRows;
use OCA\OpenRegister\Service\Flow\FlowVersionService;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for {@see FlowVersionService::deprecate()} and the failure path of {@see FlowVersionService::createDraft()}.
 *
 * @covers \OCA\OpenRegister\Service\Flow\FlowVersionService
 * @uses   \OCA\OpenRegister\Service\Flow\FlowVersionGraphs
 * @uses   \OCA\OpenRegister\Service\Flow\FlowVersionRows
 * @uses   \OCA\OpenRegister\Service\Flow\FlowLifecycleRefused
 * @uses   \OCA\OpenRegister\Db\Flow
 * @uses   \OCA\OpenRegister\Db\FlowVersion
 * @uses   \OCA\OpenRegister\Db\Flow\FlowSelection
 * @uses   \OCA\OpenRegister\Kernel\Contracts\ListQuery
 * @uses   \OCA\OpenRegister\Service\Flow\FlowGraphDiff
 * @uses   \OCA\OpenRegister\Service\Flow\FlowSemanticVersion
 */
final class FlowVersionDeprecateTest extends TestCase {

	/**
	 * The version rows.
	 *
	 * @var FlowVersionMapper&MockObject
	 */
	private FlowVersionMapper&MockObject $versions;

	/**
	 * The flow rows.
	 *
	 * @var FlowMapper&MockObject
	 */
	private FlowMapper&MockObject $flows;

	/**
	 * The connection.
	 *
	 * @var IDBConnection&MockObject
	 */
	private IDBConnection&MockObject $db;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The trigger index.
	 *
	 * @var FlowTriggerIndex&MockObject
	 */
	private FlowTriggerIndex&MockObject $triggers;

	/**
	 * The lifecycle guard.
	 *
	 * @var FlowLifecycleGuard&MockObject
	 */
	private FlowLifecycleGuard&MockObject $guard;

	/**
	 * Build the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->versions = $this->createMock(FlowVersionMapper::class);
		$this->flows    = $this->createMock(FlowMapper::class);
		$this->db       = $this->createMock(IDBConnection::class);
		$this->logger   = $this->createMock(LoggerInterface::class);
		$this->triggers = $this->createMock(FlowTriggerIndex::class);
		$this->guard    = $this->createMock(FlowLifecycleGuard::class);
	}//end setUp()

	/**
	 * The service over the mocked rows, guard and trigger index.
	 *
	 * @return FlowVersionService The service.
	 */
	private function service(): FlowVersionService {
		return new FlowVersionService(
			new FlowVersionRows($this->versions, $this->flows, $this->db, $this->logger),
			new FlowVersionGraphs($this->createMock(FlowDefinitionPin::class), $this->guard, $this->triggers, new FlowSemanticVersion())
		);
	}//end service()

	/**
	 * A published flow `flow-1`.
	 *
	 * @return Flow The flow.
	 */
	private function flow(): Flow {
		$flow = new Flow();
		$flow->setUuid('flow-1');
		$flow->setLifecycleStatus(FlowVersion::STATUS_PUBLISHED);

		return $flow;
	}//end flow()

	/**
	 * A published version 3 of `flow-1`.
	 *
	 * @return FlowVersion The row.
	 */
	private function published(): FlowVersion {
		$row = new FlowVersion();
		$row->setFlowUuid('flow-1');
		$row->setVersion(3);
		$row->setStatus(FlowVersion::STATUS_PUBLISHED);

		return $row;
	}//end published()

	/**
	 * Deprecation marks the version and the flow deprecated, forgets the triggers and commits.
	 *
	 * @return void
	 */
	public function testDeprecateRetiresTheVersionAndItsTriggers(): void {
		$flow      = $this->flow();
		$published = $this->published();
		$this->versions->method('findPublished')->with('flow-1')->willReturn($published);
		$this->versions->expects(self::once())->method('update')->with($published)->willReturnArgument(0);
		$this->flows->expects(self::once())->method('update')->with(
			self::callback(static fn (Flow $f): bool => $f->getLifecycleStatus() === FlowVersion::STATUS_DEPRECATED)
		);
		$this->guard->expects(self::once())->method('refuseDeprecateUnlessPublished')->with('flow-1', FlowVersion::STATUS_PUBLISHED);
		$this->triggers->expects(self::once())->method('forget')->with('flow-1');
		$this->db->expects(self::once())->method('beginTransaction');
		$this->db->expects(self::once())->method('commit');
		$this->db->expects(self::never())->method('rollBack');

		$before = new DateTime();
		$stored = $this->service()->deprecate(flow: $flow);

		self::assertSame($published, $stored);
		self::assertSame(FlowVersion::STATUS_DEPRECATED, $stored->getStatus());
		self::assertInstanceOf(DateTime::class, $stored->getDeprecatedAt());
		self::assertGreaterThanOrEqual($before->getTimestamp(), $stored->getDeprecatedAt()->getTimestamp());
		self::assertSame(FlowVersion::STATUS_DEPRECATED, $flow->getLifecycleStatus());
	}//end testDeprecateRetiresTheVersionAndItsTriggers()

	/**
	 * Without a published version deprecation is refused before any transaction starts.
	 *
	 * @return void
	 */
	public function testDeprecateWithoutAPublishedVersionIsRefused(): void {
		$this->versions->method('findPublished')->willReturn(null);
		$this->guard->expects(self::once())->method('refuseDeprecateUnlessPublished')->with('flow-1', null)
			->willThrowException(new FlowLifecycleRefused(reason: FlowLifecycleRefused::REASON_NOT_PUBLISHED, flowId: 'flow-1'));
		$this->versions->expects(self::never())->method('update');
		$this->db->expects(self::never())->method('beginTransaction');
		$this->triggers->expects(self::never())->method('forget');

		try {
			$this->service()->deprecate(flow: $this->flow());
			self::fail('Deprecating an unpublished flow must be refused.');
		} catch (FlowLifecycleRefused $refused) {
			self::assertSame(FlowLifecycleRefused::REASON_NOT_PUBLISHED, $refused->getReason());
		}
	}//end testDeprecateWithoutAPublishedVersionIsRefused()

	/**
	 * A failure inside the deprecation rolls back, is logged naming the flow, and is rethrown.
	 *
	 * @return void
	 */
	public function testAFailedDeprecationRollsBackAndRethrows(): void {
		$failure = new RuntimeException('flow row locked');
		$this->versions->method('findPublished')->willReturn($this->published());
		$this->flows->method('update')->willThrowException($failure);
		$this->triggers->expects(self::never())->method('forget');
		$this->db->expects(self::never())->method('commit');
		$this->db->expects(self::once())->method('rollBack');
		$this->logger->expects(self::once())->method('error')->with(
			self::equalTo('[FlowVersionService] Could not deprecate flow "flow-1": flow row locked'),
			self::callback(static fn (array $context): bool => $context['flow'] === 'flow-1')
		);

		$this->expectExceptionObject($failure);
		$this->service()->deprecate(flow: $this->flow());
	}//end testAFailedDeprecationRollsBackAndRethrows()

	/**
	 * A failure while drafting rolls back, is logged naming the flow, and is rethrown without touching the flow.
	 *
	 * @return void
	 */
	public function testAFailedDraftRollsBackAndRethrows(): void {
		$failure = new RuntimeException('versions table unreadable');
		$this->versions->method('highestVersion')->willThrowException($failure);
		$this->versions->expects(self::never())->method('insert');
		$this->flows->expects(self::never())->method('update');
		$this->db->expects(self::once())->method('beginTransaction');
		$this->db->expects(self::never())->method('commit');
		$this->db->expects(self::once())->method('rollBack');
		$this->logger->expects(self::once())->method('error')->with(
			self::equalTo('[FlowVersionService] Could not create a draft of flow "flow-1": versions table unreadable'),
			self::callback(static fn (array $context): bool => $context['flow'] === 'flow-1')
		);

		$this->expectExceptionObject($failure);
		$this->service()->createDraft(flow: $this->flow());
	}//end testAFailedDraftRollsBackAndRethrows()
}//end class
