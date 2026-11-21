<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use DateTime;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapperInterface;
use OCA\OpenRegister\Db\FlowMoment;
use OCA\OpenRegister\Db\FlowMomentMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Flow\FlowCalendarIndex;
use OCA\OpenRegister\Service\Flow\FlowTriggerSlugsInterface;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use OCA\OpenRegister\Service\Flow\Nodes\TriggerCalendarMoment;
use OCA\OpenRegister\Service\Flow\Timer\SlaCalculator;

/**
 * The calendar-trigger index: a new date places a moment, a changed date moves it,
 * a cleared date or a deleted record removes it.
 *
 * @spec openspec/changes/flow-calendar-and-webhook-triggers/tasks.md#1-calendar-trigger
 */
class FlowCalendarIndexTest extends TestCase {

	/**
	 * The index rows.
	 *
	 * @var FlowMomentMapper&MockObject
	 */
	private FlowMomentMapper&MockObject $moments;

	/**
	 * Build a flow with one calendar trigger: thirty days before `einddatum` of `contracten`.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->moments = $this->createMock(FlowMomentMapper::class);
	}//end setUp()

	/**
	 * The index under test, over one flow.
	 *
	 * @return FlowCalendarIndex
	 */
	private function index(): FlowCalendarIndex {
		$flow = new Flow();
		$flow->setUuid('flow-1');
		$flow->setNodes(
			[
				['id' => 'start', 'type' => 'openregister.trigger-manual', 'config' => []],
				[
					'id' => 'reminder',
					'type' => FlowCalendarIndex::NODE_TYPE,
					'config' => ['register' => 'contracts', 'schema' => 'contracten', 'dateProperty' => 'einddatum', 'offset' => '-P30D', 'runAs' => 'betalingen'],
				],
			]
		);

		$flows = $this->createMock(FlowMapperInterface::class);
		$flows->method('findWithNodeType')->with('openregister.trigger-calendar')->willReturn([$flow]);
		$slugs = $this->createMock(FlowTriggerSlugsInterface::class);
		$slugs->method('registerSlug')->willReturnMap([['5', 'contracts'], ['6', 'other']]);
		$slugs->method('schemaSlug')->willReturnMap([['7', 'contracten'], ['8', 'facturen']]);

		return new FlowCalendarIndex(
			flows: $flows,
			moments: $this->moments,
			slugs: $slugs,
			calendars: $this->createMock(WorkingCalendarService::class),
			logger: new NullLogger(),
			clock: new TriggerCalendarMoment(new SlaCalculator())
		);
	}//end index()

	/**
	 * A contract record.
	 *
	 * @param string|null $end    Its `einddatum`.
	 * @param string      $schema Its schema id.
	 *
	 * @return ObjectEntity
	 */
	private static function contract(?string $end, string $schema='7'): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('contract-1');
		$object->setRegister('5');
		$object->setSchema($schema);
		$object->setObject(['naam' => 'Schoonmaak', 'einddatum' => $end]);

		return $object;
	}//end contract()

	/**
	 * An indexed moment.
	 *
	 * @param string        $dateValue The date it was counted from.
	 * @param string        $due       When it is due.
	 * @param DateTime|null $fired     When it fired.
	 *
	 * @return FlowMoment
	 */
	private static function moment(string $dateValue, string $due, ?DateTime $fired=null): FlowMoment {
		$moment = new FlowMoment();
		$moment->setFlowUuid('flow-1');
		$moment->setNodeId('reminder');
		$moment->setObjectUuid('contract-1');
		$moment->setDateValue($dateValue);
		$moment->setDueAt(new DateTime($due));
		$moment->setFiredAt($fired);
		$moment->setRunUuid($fired === null ? null : 'run-1');

		return $moment;
	}//end moment()

	public function testANewDatePlacesTheMomentThirtyDaysBefore(): void {
		$this->moments->method('findMoment')->willReturn(null);
		$this->moments->expects($this->once())->method('insert')->with(
			$this->callback(
				static fn (FlowMoment $m): bool => $m->getFlowUuid() === 'flow-1'
					&& $m->getNodeId() === 'reminder'
					&& $m->getObjectUuid() === 'contract-1'
					&& $m->getDateValue() === '2027-03-31'
					&& $m->getDueAt()->format(DATE_ATOM) === '2027-03-01T00:00:00+00:00'
					&& $m->getFiredAt() === null
			)
		);

		$this->index()->index(object: self::contract(end: '2027-03-31'));
	}//end testANewDatePlacesTheMomentThirtyDaysBefore()

	public function testAMovedDateMovesTheMomentAndMakesItWaitAgain(): void {
		$existing = self::moment(dateValue: '2027-03-31', due: '2027-03-01T00:00:00+00:00', fired: new DateTime('2027-03-01T00:05:00+00:00'));
		$this->moments->method('findMoment')->with('flow-1', 'reminder', 'contract-1')->willReturn($existing);
		$this->moments->expects($this->never())->method('insert');
		$this->moments->expects($this->once())->method('update')->with($existing);

		$this->index()->index(object: self::contract(end: '2027-06-30'));

		$this->assertSame('2027-05-31T00:00:00+00:00', $existing->getDueAt()->format(DATE_ATOM));
		$this->assertSame('2027-06-30', $existing->getDateValue());
		$this->assertNull($existing->getFiredAt(), 'a new date value may fire again');
		$this->assertNull($existing->getRunUuid());
	}//end testAMovedDateMovesTheMomentAndMakesItWaitAgain()

	public function testAnUnchangedDateKeepsAFiredMomentFired(): void {
		$existing = self::moment(dateValue: '2027-03-31', due: '2027-03-01T00:00:00+00:00', fired: new DateTime('2027-03-01T00:05:00+00:00'));
		$this->moments->method('findMoment')->willReturn($existing);
		$this->moments->expects($this->never())->method('update');
		$this->moments->expects($this->never())->method('insert');

		$this->index()->index(object: self::contract(end: '2027-03-31'));

		$this->assertNotNull($existing->getFiredAt());
	}//end testAnUnchangedDateKeepsAFiredMomentFired()

	public function testAClearedDateRemovesTheMoment(): void {
		$existing = self::moment(dateValue: '2027-03-31', due: '2027-03-01T00:00:00+00:00');
		$this->moments->method('findMoment')->willReturn($existing);
		$this->moments->expects($this->once())->method('delete')->with($existing);
		$this->moments->expects($this->never())->method('insert');

		$this->index()->index(object: self::contract(end: null));
	}//end testAClearedDateRemovesTheMoment()

	public function testADeletedRecordRemovesItsMoments(): void {
		$this->moments->expects($this->once())->method('deleteForObject')->with('contract-1')->willReturn(1);

		$this->index()->remove(objectUuid: 'contract-1');
	}//end testADeletedRecordRemovesItsMoments()

	public function testARecordOfAnotherSchemaIsNotIndexed(): void {
		$this->moments->expects($this->never())->method('findMoment');
		$this->moments->expects($this->never())->method('insert');

		$this->index()->index(object: self::contract(end: '2027-03-31', schema: '8'));
	}//end testARecordOfAnotherSchemaIsNotIndexed()

	public function testOnlyCalendarTriggerNodesAreListed(): void {
		$triggers = $this->index()->triggers();

		$this->assertCount(1, $triggers);
		$this->assertSame('reminder', $triggers[0]['node']['id']);
	}//end testOnlyCalendarTriggerNodesAreListed()
}//end class
