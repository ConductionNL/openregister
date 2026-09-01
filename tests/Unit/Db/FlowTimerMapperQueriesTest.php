<?php

/**
 * The timer mapper's real queries under the fluent query-builder harness:
 * the two bounded range scans carry their full predicate set, the subject
 * and run reads filter what they claim to, and the terminal claim is decided
 * by the affected row count.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Db\FlowTimerMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\FlowTimerMapper
 * @covers \OCA\OpenRegister\Db\FlowTimer
 * @covers \OCA\OpenRegister\Db\FlowRun
 */
class FlowTimerMapperQueriesTest extends TestCase {
	use FluentQueryBuilderTrait;

	/**
	 * A stored timer row as the database returns it.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function row(): array {
		return [
			'id' => 11,
			'uuid' => 'timer-11',
			'subject_type' => 'task',
			'subject_uuid' => 'task-1',
			'purpose' => 'expiry',
			'legal_effect' => 'wettelijk',
			'state' => 'armed',
			'budget_value' => '56.0000',
			'budget_unit' => 'calendarDays',
			'consumed_value' => '19.0000',
			'anchor_at' => '2026-09-01 09:00:00',
			'fire_at' => '2026-10-27 09:00:00',
			'escalation_rules' => '[]',
			'breached' => 0,
			'created' => '2026-09-01 09:00:00',
		];
	}//end row()

	public function testInsertStampsCreatedAndGuardsTheEntityType(): void {
		$mapper = new FlowTimerMapper(db: $this->connectionWith(affectedRows: 1));
		$timer = new FlowTimer();
		$timer->setUuid('t-1');
		$inserted = $mapper->insert($timer);
		self::assertNotNull($inserted->getCreated(), 'created is stamped');
		self::assertSame(77, $inserted->getId());

		$stamped = new FlowTimer();
		$stamped->setCreated(new DateTime('2026-01-01'));
		self::assertSame('2026-01-01', $mapper->insert($stamped)->getCreated()->format('Y-m-d'), 'an explicit stamp is kept');

		$this->expectException(InvalidArgumentException::class);
		$mapper->insert(new FlowRun());
	}//end testInsertStampsCreatedAndGuardsTheEntityType()

	public function testUpdateStampsUpdatedAndGuardsTheEntityType(): void {
		$mapper = new FlowTimerMapper(db: $this->connectionWith(affectedRows: 1));
		$timer = new FlowTimer();
		$timer->setId(11);
		$timer->setUuid('t-1');
		$timer->setState(FlowTimer::STATE_ARMED);
		self::assertNotNull($mapper->update($timer)->getUpdated());

		$this->expectException(InvalidArgumentException::class);
		$mapper->update(new FlowRun());
	}//end testUpdateStampsUpdatedAndGuardsTheEntityType()

	public function testFindByUuidMapsARowAndThrowsOnNone(): void {
		$mapper = new FlowTimerMapper(db: $this->connectionWith(rows: [$this->row()]));
		$timer = $mapper->findByUuid(uuid: 'timer-11');
		self::assertSame('timer-11', $timer->getUuid());
		self::assertSame('expiry', $timer->getPurpose());
		self::assertSame(56.0, $timer->getBudgetValue());
		self::assertSame('2026-10-27', $timer->getFireAt()->format('Y-m-d'));
		self::assertTrue($this->saw('expr.eq', 'uuid'));

		$this->expectException(DoesNotExistException::class);
		(new FlowTimerMapper(db: $this->connectionWith(rows: [])))->findByUuid(uuid: 'absent');
	}//end testFindByUuidMapsARowAndThrowsOnNone()

	public function testTheExpiryScanBoundsOnStatePurposeAndMoment(): void {
		$mapper = new FlowTimerMapper(db: $this->connectionWith(rows: [$this->row()]));
		$due = $mapper->findDueExpiries(now: new DateTime('2026-10-28'), limit: 25);
		self::assertCount(1, $due);
		self::assertTrue($this->saw('expr.eq', 'state'));
		self::assertTrue($this->saw('expr.eq', 'purpose'));
		self::assertTrue($this->saw('expr.isNotNull', 'fire_at'));
		self::assertTrue($this->saw('orderBy', 'fire_at'));
		self::assertTrue($this->saw('setMaxResults', 25), 'the scan is bounded, never a page filtered in PHP');
	}//end testTheExpiryScanBoundsOnStatePurposeAndMoment()

	public function testTheRungScanBoundsOnStateAndNextRung(): void {
		$mapper = new FlowTimerMapper(db: $this->connectionWith(rows: []));
		self::assertSame([], $mapper->findDueRungs(now: new DateTime('2026-10-28'), limit: 10));
		self::assertTrue($this->saw('expr.eq', 'state'));
		self::assertTrue($this->saw('expr.isNotNull', 'next_rung_at'));
		self::assertTrue($this->saw('orderBy', 'next_rung_at'));
		self::assertTrue($this->saw('setMaxResults', 10));
	}//end testTheRungScanBoundsOnStateAndNextRung()

	public function testSubjectRunSuccessorAndPagedReadsFilterWhatTheyClaim(): void {
		$mapper = new FlowTimerMapper(db: $this->connectionWith(rows: [$this->row()]));
		$mapper->findBySubject(subjectType: 'task', subjectUuid: 'task-1', states: [FlowTimer::STATE_ARMED]);
		self::assertTrue($this->saw('expr.eq', 'subject_type'));
		self::assertTrue($this->saw('expr.eq', 'subject_uuid'));
		self::assertTrue($this->saw('expr.in', 'state'));

		$mapper = new FlowTimerMapper(db: $this->connectionWith(rows: []));
		$mapper->findOpenByRun(runUuid: 'run-1');
		self::assertTrue($this->saw('expr.in', 'state'), 'a run read only reaches open timers');

		$mapper = new FlowTimerMapper(db: $this->connectionWith(rows: []));
		$mapper->findSuccessors(uuid: 'timer-11');
		self::assertTrue($this->saw('expr.eq', 'supersedes_uuid'));

		$mapper = new FlowTimerMapper(db: $this->connectionWith(rows: []));
		$mapper->findByStatePaged(state: FlowTimer::STATE_ARMED, afterId: 40, limit: 500);
		self::assertTrue($this->saw('expr.gt', 'id'));
		self::assertTrue($this->saw('setMaxResults', 500));
	}//end testSubjectRunSuccessorAndPagedReadsFilterWhatTheyClaim()

	public function testAnUnrestrictedSubjectReadCarriesNoStatePredicate(): void {
		$mapper = new FlowTimerMapper(db: $this->connectionWith(rows: []));
		$mapper->findBySubject(subjectType: 'task', subjectUuid: 'task-1');
		self::assertTrue($this->saw('expr.eq', 'subject_type'));
		self::assertFalse($this->saw('expr.in', 'state'), 'no state restriction when none is asked');
	}//end testAnUnrestrictedSubjectReadCarriesNoStatePredicate()

	public function testClaimFiredIsDecidedByTheAffectedRowCount(): void {
		$mapper = new FlowTimerMapper(db: $this->connectionWith(affectedRows: 1));
		self::assertTrue($mapper->claimFired(uuid: 'timer-11', firedAt: new DateTime('2026-10-28')));
		self::assertTrue($this->saw('set', 'state'));
		self::assertTrue($this->saw('set', 'fired_at'));
		self::assertTrue($this->saw('expr.eq', 'state'), 'the claim is conditional on armed');

		self::assertFalse((new FlowTimerMapper(db: $this->connectionWith(affectedRows: 0)))->claimFired(uuid: 'timer-11', firedAt: new DateTime()), 'zero rows means another pass owns it');
	}//end testClaimFiredIsDecidedByTheAffectedRowCount()
}//end class
