<?php

/**
 * FlowHopAudit: which runs write a hop row, and what the row says.
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
 * @spec openspec/changes/flow-engine-unification/specs/flow-oversight/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\AuditFlowAttribution;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunStep;
use OCA\OpenRegister\Db\FlowRunStepMapper;
use OCA\OpenRegister\Service\Flow\FlowHopAudit;
use OCA\OpenRegister\Service\Flow\FlowStepHistory;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowHopAudit
 * @uses   \OCA\OpenRegister\Db\AuditTrail
 * @uses   \OCA\OpenRegister\Db\Flow
 * @uses   \OCA\OpenRegister\Db\FlowRun
 * @uses   \OCA\OpenRegister\Db\FlowRunStep
 * @uses   \OCA\OpenRegister\Service\Flow\FlowStepHistory
 * @uses \OCA\OpenRegister\Service\Flow\FlowStepOutcome
 */
class FlowHopAuditTest extends TestCase {

	/** @var array<int, AuditTrail> */
	private array $rows = [];

	/**
	 * The audit writer, collecting rows.
	 *
	 * @return AuditTrailMapperInterface The mapper.
	 */
	private function trail(): AuditTrailMapperInterface {
		$audit = $this->createMock(AuditTrailMapperInterface::class);
		$audit->method('insertAuditTrails')->willReturnCallback(function (array $entries): array {
			array_push($this->rows, ...$entries);
			return $entries;
		});

		return $audit;
	}//end trail()

	/**
	 * The service over a flow table and an instance default.
	 *
	 * @param array<string, bool|null> $flags   The `auditEnabled` per flow uuid.
	 * @param bool                     $default The instance default.
	 *
	 * @return FlowHopAudit The service.
	 */
	private function service(array $flags, bool $default = false): FlowHopAudit {
		$flows = $this->createMock(FlowMapper::class);
		$flows->method('findByUuid')->willReturnCallback(function (string $uuid) use ($flags): Flow {
			if (array_key_exists($uuid, $flags) === false) {
				throw new DoesNotExistException('no flow');
			}

			$flow = new Flow();
			$flow->setAuditEnabled($flags[$uuid]);
			return $flow;
		});

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueBool')
			->with('openregister', 'flow_audit_enabled', false)
			->willReturn($default);

		return new FlowHopAudit(audit: $this->trail(), flows: $flows, appConfig: $config, logger: new NullLogger());
	}//end service()

	/**
	 * A run of a flow.
	 *
	 * @param string $flowId The flow.
	 *
	 * @return FlowRun The run.
	 */
	private function aRun(string $flowId = 'flow-1'): FlowRun {
		$run = new FlowRun();
		$run->setUuid('run-1');
		$run->setFlowId($flowId);
		$run->setRunAs('anna');
		$run->setTriggeredBy('bram');
		$run->setSubjectUuid('subject-1');
		$run->setOrganisation('org-1');

		return $run;
	}//end aRun()

	/**
	 * A step row.
	 *
	 * @return FlowRunStep The step.
	 */
	private function step(): FlowRunStep {
		$step = new FlowRunStep();
		$step->setNodeId('send');
		$step->setNodeType('core.email');
		$step->setSequence(7);
		$step->setStreamId('stream-1');
		$step->setStatus('completed');
		$step->setDurationMs(12);
		$step->setOutput(['itemsIn' => 2, 'itemsOut' => 3, 'checkId' => 'kill']);

		return $step;
	}//end step()

	public function testAnUnsetFlowOnAnInstanceWithAuditingOffWritesNoRow(): void {
		$this->service(['flow-1' => null])->record(run: $this->aRun(), step: $this->step());

		$this->assertSame([], $this->rows);
	}//end testAnUnsetFlowOnAnInstanceWithAuditingOffWritesNoRow()

	public function testAnUnsetFlowFollowsAnInstanceDefaultThatIsOn(): void {
		$this->service(['flow-1' => null], default: true)->record(run: $this->aRun(), step: $this->step());

		$this->assertCount(1, $this->rows);
	}//end testAnUnsetFlowFollowsAnInstanceDefaultThatIsOn()

	/**
	 * The same service serves two flows: remembering the answer per flow must
	 * not carry one flow's answer over to the other.
	 */
	public function testAFlowThatOptsInIsAuditedAndAnotherFlowIsNot(): void {
		$service = $this->service(['flow-1' => true, 'flow-2' => null]);

		$service->record(run: $this->aRun(flowId: 'flow-1'), step: $this->step());
		$service->record(run: $this->aRun(flowId: 'flow-2'), step: $this->step());
		$service->record(run: $this->aRun(flowId: 'flow-1'), step: $this->step());

		$this->assertCount(2, $this->rows);
		$this->assertSame('flow-1', $this->rows[1]->getChanged()['flow']);
	}//end testAFlowThatOptsInIsAuditedAndAnotherFlowIsNot()

	public function testAFlowThatOptsOutBeatsAnInstanceDefaultThatIsOn(): void {
		$this->service(['flow-1' => false], default: true)->record(run: $this->aRun(), step: $this->step());

		$this->assertSame([], $this->rows);
	}//end testAFlowThatOptsOutBeatsAnInstanceDefaultThatIsOn()

	public function testARunWhoseFlowIsGoneFollowsTheInstanceDefault(): void {
		$this->service([], default: true)->record(run: $this->aRun(), step: $this->step());
		$this->assertCount(1, $this->rows);

		$this->rows = [];
		$this->service([], default: false)->record(run: $this->aRun(), step: $this->step());
		$this->assertSame([], $this->rows);
	}//end testARunWhoseFlowIsGoneFollowsTheInstanceDefault()

	/**
	 * Its flag is unknown, and a flow that asked for auditing must not lose
	 * rows to a read failure.
	 */
	public function testAFlowThatCannotBeReadIsAudited(): void {
		$flows = $this->createMock(FlowMapper::class);
		$flows->method('findByUuid')->willThrowException(new RuntimeException('db gone'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$service = new FlowHopAudit(
			audit: $this->trail(),
			flows: $flows,
			appConfig: $this->createMock(IAppConfig::class),
			logger: $logger
		);
		$service->record(run: $this->aRun(), step: $this->step());

		$this->assertCount(1, $this->rows);
	}//end testAFlowThatCannotBeReadIsAudited()

	public function testTheRowIsAttributedToTheRunNodeAndStep(): void {
		$this->service(['flow-1' => true])->record(run: $this->aRun(), step: $this->step());

		$row = $this->rows[0];
		$this->assertNotEmpty($row->getUuid());
		$this->assertSame(AuditFlowAttribution::ACTION_HOP, $row->getAction());
		$this->assertSame('run-1', $row->getFlowRun());
		$this->assertSame('send', $row->getFlowNode());
		$this->assertSame(7, $row->getFlowStep());
		$this->assertSame('anna', $row->getUser());
		$this->assertNotNull($row->getCreated());
	}//end testTheRowIsAttributedToTheRunNodeAndStep()

	public function testARunWithNoActingIdentityFallsBackToWhoTriggeredItThenSystem(): void {
		$service = $this->service(['flow-1' => true]);
		$run = $this->aRun();
		$run->setRunAs(null);
		$service->record(run: $run, step: $this->step());

		$run->setTriggeredBy(null);
		$service->record(run: $run, step: $this->step());

		$this->assertSame('bram', $this->rows[0]->getUser());
		$this->assertSame('system', $this->rows[1]->getUser());
		$this->assertSame('System', $this->rows[1]->getUserName());
	}//end testARunWithNoActingIdentityFallsBackToWhoTriggeredItThenSystem()

	public function testTheRowSaysWhatRanInIdentifiersAndCounts(): void {
		$this->service(['flow-1' => true])->record(run: $this->aRun(), step: $this->step());

		$this->assertSame(
			[
				'flow' => 'flow-1',
				'organisation' => 'org-1',
				'subject' => 'subject-1',
				'nodeType' => 'core.email',
				'status' => 'completed',
				'streamId' => 'stream-1',
				'durationMs' => 12,
				'failed' => false,
				'itemsIn' => 2,
				'itemsOut' => 3,
				'checkId' => 'kill',
			],
			$this->rows[0]->getChanged()
		);
	}//end testTheRowSaysWhatRanInIdentifiersAndCounts()

	/**
	 * The audit trail is append-only and an error message can quote a value from
	 * the run, so only the fact of the failure goes on it.
	 */
	public function testAStepErrorIsMarkedButItsTextStaysOffTheTrail(): void {
		$step = $this->step();
		$step->setStatus('failed');
		$step->setError('rejected token s3cret for jan@example.org');

		$this->service(['flow-1' => true])->record(run: $this->aRun(), step: $step);

		$this->assertTrue($this->rows[0]->getChanged()['failed']);
		$this->assertStringNotContainsString('s3cret', (string)json_encode($this->rows[0]->getChanged()));
	}//end testAStepErrorIsMarkedButItsTextStaysOffTheTrail()

	/**
	 * `object_uuid` rows are read back as the object's change history and as
	 * revert points. A hop is neither.
	 */
	public function testTheRowIsNotFiledUnderTheSubject(): void {
		$this->service(['flow-1' => true])->record(run: $this->aRun(), step: $this->step());

		$this->assertNull($this->rows[0]->getObjectUuid());
		$this->assertNull($this->rows[0]->getObject());
	}//end testTheRowIsNotFiledUnderTheSubject()

	/**
	 * The hop already happened, so a failed write is logged and the run goes on.
	 */
	public function testAFailedWriteIsLoggedAndDoesNotThrow(): void {
		$audit = $this->createMock(AuditTrailMapperInterface::class);
		$audit->method('insertAuditTrails')->willThrowException(new RuntimeException('disk full'));
		$flows = $this->createMock(FlowMapper::class);
		$flows->method('findByUuid')->willReturnCallback(function (): Flow {
			$flow = new Flow();
			$flow->setAuditEnabled(true);
			return $flow;
		});
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error');

		$service = new FlowHopAudit(
			audit: $audit,
			flows: $flows,
			appConfig: $this->createMock(IAppConfig::class),
			logger: $logger
		);
		$service->record(run: $this->aRun(), step: $this->step());
	}//end testAFailedWriteIsLoggedAndDoesNotThrow()

	/**
	 * One row per step row on the after-the-walk path, with the same number, and
	 * none for an entry the commit path already recorded and audited.
	 */
	public function testTheStepHistoryAuditsEachHopItRecordsAndNoOther(): void {
		$steps = $this->createMock(FlowRunStepMapper::class);
		$steps->method('highestSequence')->willReturn(4);

		$history = new FlowStepHistory(steps: $steps, hopAudit: $this->service(['flow-1' => true]));
		$history->record(
			run: $this->aRun(),
			entries: [
				['transition' => 'a', 'status' => 'completed'],
				['transition' => 'done-by-commit', 'status' => 'completed', 'recorded' => true],
				['transition' => 'b', 'status' => 'stopped', 'checkId' => 'kill'],
			]
		);

		$this->assertSame(
			[['a', 5], ['b', 6]],
			array_map(static fn (AuditTrail $row): array => [$row->getFlowNode(), $row->getFlowStep()], $this->rows)
		);
		$this->assertSame('kill', $this->rows[1]->getChanged()['checkId']);
	}//end testTheStepHistoryAuditsEachHopItRecordsAndNoOther()

	/**
	 * A step row that could not be written does not undo the hop.
	 */
	public function testAHopIsAuditedEvenWhenItsStepRowFailed(): void {
		$steps = $this->createMock(FlowRunStepMapper::class);
		$steps->method('insert')->willThrowException(new RuntimeException('db gone'));

		$history = new FlowStepHistory(
			steps: $steps,
			logger: new NullLogger(),
			hopAudit: $this->service(['flow-1' => true])
		);
		$history->record(run: $this->aRun(), entries: [['transition' => 'a', 'status' => 'completed']]);

		$this->assertCount(1, $this->rows);
	}//end testAHopIsAuditedEvenWhenItsStepRowFailed()
}//end class
