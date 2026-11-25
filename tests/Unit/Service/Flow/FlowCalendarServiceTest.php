<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use DateTime;
use DateTimeImmutable;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMoment;
use OCA\OpenRegister\Db\FlowMomentMapper;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Service\Flow\FlowCalendarIndex;
use OCA\OpenRegister\Service\Flow\FlowCalendarRecords;
use OCA\OpenRegister\Service\Flow\FlowCalendarService;
use OCA\OpenRegister\Service\Flow\FlowRunQueueOptions;
use OCA\OpenRegister\Service\Flow\FlowRunServiceInterface;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Firing the due calendar moments: once and only once, never for moments
 * that lay before the trigger was enabled, and not for a disabled flow.
 *
 * @spec openspec/changes/flow-calendar-and-webhook-triggers/tasks.md#1-calendar-trigger
 */
class FlowCalendarServiceTest extends TestCase {

	/**
	 * In-memory app config.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * The index rows.
	 *
	 * @var FlowMomentMapper&MockObject
	 */
	private FlowMomentMapper&MockObject $moments;

	/**
	 * The run queue.
	 *
	 * @var FlowRunServiceInterface&MockObject
	 */
	private FlowRunServiceInterface&MockObject $runs;

	/**
	 * The index, as the service reads its triggers.
	 *
	 * @var FlowCalendarIndex&MockObject
	 */
	private FlowCalendarIndex&MockObject $index;

	/**
	 * The records that exist when the trigger is enabled.
	 *
	 * @var FlowCalendarRecords&MockObject
	 */
	private FlowCalendarRecords&MockObject $records;

	/**
	 * Mocks, and the trigger: a flow saved enabled on 1 February 2027.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->config = [];
		$this->moments = $this->createMock(FlowMomentMapper::class);
		$this->runs = $this->createMock(FlowRunServiceInterface::class);
		$this->index = $this->createMock(FlowCalendarIndex::class);
		$this->records = $this->createMock(FlowCalendarRecords::class);
	}//end setUp()

	/**
	 * The service over one trigger.
	 *
	 * @param bool $enabled Whether its flow is enabled.
	 *
	 * @return FlowCalendarService
	 */
	private function service(bool $enabled=true): FlowCalendarService {
		$flow = new Flow();
		$flow->setUuid('flow-1');
		$flow->setEnabled($enabled);
		$flow->setOwner('admin');
		$flow->setUpdated(new DateTime('2027-02-01T12:00:00+00:00'));
		$node = [
			'id' => 'reminder',
			'type' => FlowCalendarIndex::NODE_TYPE,
			'config' => ['register' => 'contracts', 'schema' => 'contracten', 'dateProperty' => 'einddatum', 'offset' => '-P30D', 'runAs' => 'betalingen'],
		];
		$this->index->method('triggers')->willReturn([['flow' => $flow, 'node' => $node]]);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default=''): string => ($this->config[$key] ?? $default));
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$key] = $value;
				return true;
			}
		);
		$appConfig->method('deleteKey')->willReturnCallback(
			function (string $app, string $key): void {
				unset($this->config[$key]);
			}
		);

		return new FlowCalendarService(
			index: $this->index,
			records: $this->records,
			moments: $this->moments,
			runs: $this->runs,
			appConfig: $appConfig,
			logger: new NullLogger()
		);
	}//end service()

	/**
	 * A waiting moment of the trigger.
	 *
	 * @param string $objectUuid The record.
	 * @param string $due        When it is due.
	 *
	 * @return FlowMoment
	 */
	private static function moment(string $objectUuid, string $due): FlowMoment {
		$moment = new FlowMoment();
		$moment->setId(1);
		$moment->setFlowUuid('flow-1');
		$moment->setNodeId('reminder');
		$moment->setObjectUuid($objectUuid);
		$moment->setDateValue('2027-03-31');
		$moment->setDueAt(new DateTime($due));

		return $moment;
	}//end moment()

	public function testADueMomentQueuesOneRunAndOnlyOnce(): void {
		$moment = self::moment(objectUuid: 'contract-1', due: '2027-03-01T00:00:00+00:00');
		$this->moments->method('findDue')->willReturn([$moment]);
		// The second tick finds the row still listed (a stale read) but loses the claim.
		$this->moments->expects($this->exactly(2))->method('claim')->willReturnOnConsecutiveCalls(true, false);
		$run = new FlowRun();
		$run->setUuid('run-1');
		$this->runs->expects($this->once())->method('queueRun')->with(
			'flow-1',
			$this->callback(
				static fn (FlowRunQueueOptions $o): bool => $o->trigger === 'calendar'
					&& $o->subject === ['uuid' => 'contract-1', 'register' => 'contracts', 'schema' => 'contracten']
					&& $o->context['payload']['dateValue'] === '2027-03-31'
					&& $o->context['payload']['nodeId'] === 'reminder'
			)
		)->willReturn($run);
		$this->moments->expects($this->once())->method('recordRun')->with($moment, 'run-1');

		$service = $this->service();
		$this->assertSame(['run-1'], $service->fireDue(now: new DateTimeImmutable('2027-03-01T00:05:00+00:00')));
		$this->assertSame([], $service->fireDue(now: new DateTimeImmutable('2027-03-01T00:10:00+00:00')));
	}//end testADueMomentQueuesOneRunAndOnlyOnce()

	public function testEnablingPlacesTheExistingRecordsAndSkipsThePastOnes(): void {
		$this->records->expects($this->once())->method('recordsOf')->with('contracts', 'contracten')->willReturn(
			[
				'c-1' => ['einddatum' => '2026-12-01'],
				'c-2' => ['einddatum' => '2027-01-01'],
				'c-3' => ['einddatum' => '2027-02-15'],
				'c-4' => ['einddatum' => '2027-03-10'],
			]
		);
		$this->index->expects($this->exactly(4))->method('place');
		$past = [
			self::moment(objectUuid: 'c-1', due: '2026-11-01T00:00:00+00:00'),
			self::moment(objectUuid: 'c-2', due: '2026-12-02T00:00:00+00:00'),
			self::moment(objectUuid: 'c-3', due: '2027-01-16T00:00:00+00:00'),
		];
		$this->moments->method('findDue')->willReturn($past);
		$this->moments->expects($this->exactly(3))->method('claim')->with($this->anything(), $this->anything(), true)->willReturn(true);
		$this->runs->expects($this->never())->method('queueRun');

		$service = $this->service();
		$this->assertSame([], $service->fireDue(now: new DateTimeImmutable('2027-02-01T12:05:00+00:00')));
		$this->assertSame(3, $service->skippedBeforeEnabling(flowUuid: 'flow-1', nodeId: 'reminder'));
	}//end testEnablingPlacesTheExistingRecordsAndSkipsThePastOnes()

	public function testTheRecordsArePlacedOnlyOnTheFirstTickAfterEnabling(): void {
		$this->moments->method('findDue')->willReturn([]);
		$this->records->expects($this->once())->method('recordsOf')->willReturn([]);

		$service = $this->service();
		$service->fireDue(now: new DateTimeImmutable('2027-02-01T12:05:00+00:00'));
		$service->fireDue(now: new DateTimeImmutable('2027-02-01T12:10:00+00:00'));
	}//end testTheRecordsArePlacedOnlyOnTheFirstTickAfterEnabling()

	public function testADisabledFlowFiresNothingAndForgetsItsEnabling(): void {
		$this->config['flow_cal_enabled_' . md5('flow-1|reminder')] = '1785585600';
		$this->moments->expects($this->never())->method('findDue');
		$this->runs->expects($this->never())->method('queueRun');

		$this->assertSame([], $this->service(enabled: false)->fireDue(now: new DateTimeImmutable('2027-03-01T00:05:00+00:00')));
		$this->assertArrayNotHasKey('flow_cal_enabled_' . md5('flow-1|reminder'), $this->config);
	}//end testADisabledFlowFiresNothingAndForgetsItsEnabling()

	public function testARefusedRunLeavesTheMomentFiredWithoutARun(): void {
		$this->moments->method('findDue')->willReturn([self::moment(objectUuid: 'contract-1', due: '2027-03-01T00:00:00+00:00')]);
		$this->moments->expects($this->once())->method('claim')->willReturn(true);
		$this->runs->method('queueRun')->willThrowException(new RuntimeException('no acting identity'));
		$this->moments->expects($this->never())->method('recordRun');

		$this->assertSame([], $this->service()->fireDue(now: new DateTimeImmutable('2027-03-01T00:05:00+00:00')));
	}//end testARefusedRunLeavesTheMomentFiredWithoutARun()
}//end class
