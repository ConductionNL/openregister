<?php

/**
 * Plan-item deadlines on the shared clock.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Case
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-deadlines-run-on-the-shared-clock
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Case;

use DateTime;
use DateTimeImmutable;
use OCA\OpenRegister\Db\CaseItem;
use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Event\FlowTimerFiredEvent;
use OCA\OpenRegister\Service\Case\CasePlanItemTimers;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The case layer decides what a deadline means for an item; the timer core
 * decides when.
 */
class CasePlanItemTimersTest extends TestCase {

	private FlowTimerService&MockObject $timers;

	/**
	 * Fresh core.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->timers = $this->createMock(FlowTimerService::class);
	}//end setUp()

	/**
	 * The service.
	 *
	 * @return CasePlanItemTimers The service.
	 */
	private function service(): CasePlanItemTimers {
		return new CasePlanItemTimers(timers: $this->timers, logger: new NullLogger());
	}//end service()

	/**
	 * `case-item` is a subject the core accepts.
	 *
	 * @return void
	 */
	public function testCaseItemIsATimerSubject(): void {
		$this->assertContains(CasePlanItemTimers::SUBJECT_TYPE, FlowTimer::SUBJECT_TYPES);
		$this->assertLessThanOrEqual(16, strlen(CasePlanItemTimers::SUBJECT_TYPE), 'subject_type is a 16-character column.');
	}//end testCaseItemIsATimerSubject()

	/**
	 * An item entering active with a due date arms an advisory due timer
	 * that lands exactly on the due date; an expiry on a statutory item arms
	 * an enforcing one that terminates it; an expiry elsewhere advises.
	 *
	 * @return void
	 */
	public function testArmsDueAndExpiryTimersWhenAnItemBecomesActive(): void {
		$now = new DateTimeImmutable('2026-10-10T10:00:00+00:00');
		$item = CaseFixtures::row(id: 5, key: 'beoordeling', type: CaseItem::TYPE_HUMAN_TASK, state: CaseItem::STATE_ACTIVE);
		$item->setDueAt(new DateTime('2026-10-12T10:30:00+00:00'));
		$item->setExpiresAt(new DateTime('2026-11-10T10:00:00+00:00'));
		$item->setPlanSettings(['statutory' => ['beoordeling']]);

		$armed = [];
		$this->timers->expects($this->exactly(2))->method('arm')->willReturnCallback(
			static function (array $config, ?string $actor) use (&$armed): FlowTimer {
				$armed[] = [$config, $actor];

				return new FlowTimer();
			}
		);

		$this->assertSame(2, $this->service()->onEntered(item: $item, actor: 'alice', now: $now));

		[$due, $actor] = $armed[0];
		$this->assertSame('alice', $actor);
		$this->assertSame(CasePlanItemTimers::SUBJECT_TYPE, $due['subjectType']);
		$this->assertSame('item-5', $due['subjectUuid']);
		$this->assertSame(FlowTimer::PURPOSE_DUE, $due['purpose']);
		$this->assertSame(FlowTimer::LEGAL_NONE, $due['legalEffect']);
		$this->assertArrayNotHasKey('onExpiry', $due);
		$this->assertSame(['value' => 49, 'unit' => 'hours'], $due['sla']);
		$landing = (new DateTimeImmutable($due['anchorEventAt']))->modify('+49 hours');
		$this->assertSame('2026-10-12T10:30:00+00:00', $landing->format('c'), 'The anchor is chosen so the deadline lands on dueAt exactly.');

		[$expiry] = $armed[1];
		$this->assertSame(FlowTimer::PURPOSE_EXPIRY, $expiry['purpose']);
		$this->assertSame(FlowTimer::LEGAL_WETTELIJK, $expiry['legalEffect']);
		$this->assertSame(CasePlanItemTimers::ON_EXPIRY, $expiry['onExpiry']);

		$item->setPlanSettings([]);
		$armed = [];
		$this->setUp();
		$this->timers->method('arm')->willReturnCallback(
			static function (array $config) use (&$armed): FlowTimer {
				$armed[] = $config;

				return new FlowTimer();
			}
		);
		$item->setDueAt(null);
		$this->service()->onEntered(item: $item, actor: 'alice', now: $now);
		$this->assertSame(FlowTimer::LEGAL_SERVICENORM, $armed[0]['legalEffect']);
		$this->assertArrayNotHasKey('onExpiry', $armed[0], 'A non-statutory expiry advises; it never terminates.');
	}//end testArmsDueAndExpiryTimersWhenAnItemBecomesActive()

	/**
	 * A deadline beyond the hour budget is measured in calendar days; a
	 * deadline already past, or an item without deadlines, arms nothing; a
	 * refused arm is logged and never thrown.
	 *
	 * @return void
	 */
	public function testEdgesNeverBlockTheTransition(): void {
		$now = new DateTimeImmutable('2026-10-10T10:00:00+00:00');
		$far = CaseFixtures::row(id: 6, key: 'far', type: CaseItem::TYPE_HUMAN_TASK, state: CaseItem::STATE_ACTIVE);
		$far->setDueAt(new DateTime('2028-10-10T10:00:00+00:00'));
		$configs = [];
		$this->timers->method('arm')->willReturnCallback(
			static function (array $config) use (&$configs): FlowTimer {
				$configs[] = $config;

				return new FlowTimer();
			}
		);
		$this->service()->onEntered(item: $far, actor: null, now: $now);
		$this->assertSame('calendarDays', $configs[0]['sla']['unit']);
		$this->assertSame(731, $configs[0]['sla']['value']);

		$past = CaseFixtures::row(id: 7, key: 'past', type: CaseItem::TYPE_HUMAN_TASK, state: CaseItem::STATE_ACTIVE);
		$past->setDueAt(new DateTime('2026-10-09T10:00:00+00:00'));
		$this->assertSame(0, $this->service()->onEntered(item: $past, actor: null, now: $now));
		$plain = CaseFixtures::row(id: 8, key: 'plain', type: CaseItem::TYPE_HUMAN_TASK, state: CaseItem::STATE_ACTIVE);
		$this->assertSame(0, $this->service()->onEntered(item: $plain, actor: null, now: $now));
		$this->assertCount(1, $configs);

		$this->setUp();
		$this->timers->method('arm')->willThrowException(new RuntimeException('calendar unknown'));
		$this->assertSame(0, $this->service()->onEntered(item: $far, actor: null, now: $now));
	}//end testEdgesNeverBlockTheTransition()

	/**
	 * Reaching a terminal state cancels the item's open timers; a failure to
	 * cancel is logged, never thrown.
	 *
	 * @return void
	 */
	public function testTerminalCancels(): void {
		$item = CaseFixtures::row(id: 5, key: 'beoordeling', type: CaseItem::TYPE_HUMAN_TASK, state: CaseItem::STATE_COMPLETED);
		$this->timers->expects($this->once())->method('cancelForSubject')
			->with(CasePlanItemTimers::SUBJECT_TYPE, 'item-5', $this->stringContains('completed'), 'case-plan')
			->willReturn(2);
		$this->assertSame(2, $this->service()->onTerminal(item: $item));

		$this->setUp();
		$this->timers->method('cancelForSubject')->willThrowException(new RuntimeException('db down'));
		$this->assertSame(0, $this->service()->onTerminal(item: $item));
	}//end testTerminalCancels()

	/**
	 * Only an enforcing expiry on a case-item subject terminates an item.
	 *
	 * @return void
	 */
	public function testOnlyAnEnforcingExpiryOnACaseItemTerminates(): void {
		$timer = new FlowTimer();
		$timer->setUuid('timer-1');
		$timer->setSubjectType(CasePlanItemTimers::SUBJECT_TYPE);
		$timer->setSubjectUuid('item-5');
		$timer->setPurpose(FlowTimer::PURPOSE_EXPIRY);
		$timer->setLegalEffect(FlowTimer::LEGAL_WETTELIJK);
		$timer->setOnExpiry(CasePlanItemTimers::ON_EXPIRY);

		$fire = static fn (FlowTimer $t, string $kind): FlowTimerFiredEvent => new FlowTimerFiredEvent(
			timer: $t,
			kind: $kind,
			transition: 'expiry',
			rungKey: null,
			recipients: [],
			priority: null,
			message: null
		);

		$this->assertSame('item-5', $this->service()->itemToTerminate(event: $fire($timer, FlowTimerFiredEvent::KIND_EXPIRY)));
		$this->assertNull($this->service()->itemToTerminate(event: $fire($timer, FlowTimerFiredEvent::KIND_RUNG)));

		$advisory = clone $timer;
		$advisory->setLegalEffect(FlowTimer::LEGAL_SERVICENORM);
		$advisory->setOnExpiry(null);
		$this->assertNull($this->service()->itemToTerminate(event: $fire($advisory, FlowTimerFiredEvent::KIND_EXPIRY)));

		$task = clone $timer;
		$task->setSubjectType('task');
		$this->assertNull($this->service()->itemToTerminate(event: $fire($task, FlowTimerFiredEvent::KIND_EXPIRY)));
	}//end testOnlyAnEnforcingExpiryOnACaseItemTerminates()
}//end class
