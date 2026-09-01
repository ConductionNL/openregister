<?php

/**
 * The ladder: commensurable-unit validation on the timeline, rung ordering,
 * a downtime gap firing every passed rung in order, and recipient
 * descriptors resolved from the subject's performer, group path included.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Timer
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Timer;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Exception\FlowTimerValidationException;
use OCA\OpenRegister\Service\Flow\Timer\EscalationLadderService;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerDefinitionStore;
use OCA\OpenRegister\Service\Flow\Timer\SlaCalculator;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Timer\EscalationLadderService
 */
class EscalationLadderServiceTest extends TestCase {

	private FlowTimerDefinitionStore&MockObject $definitions;

	private EscalationLadderService $ladder;

	private WorkingCalendar $calendar;

	private DateTimeZone $tz;

	protected function setUp(): void {
		parent::setUp();
		$this->definitions = $this->createMock(FlowTimerDefinitionStore::class);
		$this->definitions->method('ladders')->willReturn(self::seededLadders());
		$this->ladder = new EscalationLadderService(definitions: $this->definitions, calculator: new SlaCalculator());
		$this->calendar = WorkingCalendar::fromArray(definition: WorkingCalendarTest::nlNational());
		$this->tz = new DateTimeZone('Europe/Amsterdam');
	}//end setUp()

	/**
	 * The seeded ladders from the shipped descriptor, keyed by slug, plus an organisation default.
	 *
	 * @return array<string, array<string, mixed>> The ladders.
	 */
	public static function seededLadders(): array {
		$data = json_decode((string)file_get_contents(__DIR__ . '/../../../../../lib/Settings/flow_timer_register.json'), true);
		$ladders = [];
		foreach ($data['components']['objects'] as $object) {
			if (($object['@self']['schema'] ?? '') === 'escalation-ladder') {
				unset($object['@self']);
				$ladders[$object['slug']] = $object;
			}
		}

		$org = $ladders['nl-termijn-default'];
		$org['slug'] = 'org-ladder';
		$org['organisation'] = 'org-1';
		$org['roleBindings'] = ['teamleader' => 'group:teamleaders'];
		$ladders['org-ladder'] = $org;

		return $ladders;
	}//end seededLadders()

	private function at(string $when): DateTimeImmutable {
		return new DateTimeImmutable($when, $this->tz);
	}//end at()

	private function timer(?string $ladderSlug = 'nl-termijn-default', ?array $rules = null, ?string $organisation = null): FlowTimer {
		$timer = new FlowTimer();
		$timer->setUuid('t-1');
		$timer->setBudgetValue(56.0);
		$timer->setBudgetUnit('calendarDays');
		$timer->setLadderSlug($ladderSlug);
		$timer->setEscalationRules($rules);
		$timer->setOrganisation($organisation);

		return $timer;
	}//end timer()

	public function testSeededDefaultLadderIsFourteenSevenTwoZero(): void {
		$rungs = $this->ladder->resolveLadder(timer: $this->timer())['rungs'];
		self::assertSame([14, 7, 2, 0], array_column($rungs, 'offset'));
		self::assertSame(['low', 'medium', 'high', 'critical'], array_column($rungs, 'priority'));
		self::assertSame(['handler'], $rungs[0]['notifyRole']);
		self::assertSame(['handler', 'teamleader'], $rungs[1]['notifyRole']);
		self::assertSame(['handler', 'teamleader', 'manager'], $rungs[2]['notifyRole']);
		self::assertTrue($rungs[3]['openIncident']);
		self::assertSame('slaBreached', $rungs[3]['trigger']);
	}//end testSeededDefaultLadderIsFourteenSevenTwoZero()

	public function testInlineRulesWinAndOrganisationDefaultIsUsedWhenNothingIsNamed(): void {
		$inline = $this->ladder->resolveLadder(
			timer: $this->timer(ladderSlug: 'nl-termijn-default', rules: [['trigger' => 'preBreach', 'offset' => 3, 'offsetUnit' => 'businessDays', 'notifyRole' => 'handler']])
		);
		self::assertCount(1, $inline['rungs']);
		self::assertSame('preBreach:3:businessDays', $inline['rungs'][0]['key']);

		$org = $this->ladder->resolveLadder(timer: $this->timer(ladderSlug: null, organisation: 'org-1'));
		self::assertCount(4, $org['rungs']);
		self::assertSame(['teamleader' => 'group:teamleaders'], $org['roleBindings']);

		self::assertSame([], $this->ladder->resolveLadder(timer: $this->timer(ladderSlug: null))['rungs']);
	}//end testInlineRulesWinAndOrganisationDefaultIsUsedWhenNothingIsNamed()

	public function testUnknownLadderIsRefused(): void {
		$this->expectException(FlowTimerValidationException::class);
		$this->expectExceptionMessage("Escalation ladder 'nope' does not exist");
		$this->ladder->resolveLadder(timer: $this->timer(ladderSlug: 'nope'));
	}//end testUnknownLadderIsRefused()

	public function testARuleWithoutAnSlaIsRefused(): void {
		$this->expectException(FlowTimerValidationException::class);
		$this->expectExceptionMessage('refused without an SLA');
		$this->ladder->normaliseRules(rules: [['trigger' => 'preBreach', 'offset' => 1, 'offsetUnit' => 'hours']], sla: null);
	}//end testARuleWithoutAnSlaIsRefused()

	public function testOffsetUnitAcceptsCalendarDaysAndRefusesTheRest(): void {
		$sla = ['value' => 2, 'unit' => 'calendarDays'];
		$rungs = $this->ladder->normaliseRules(rules: [['trigger' => 'preBreach', 'offset' => 1, 'offsetUnit' => 'calendarDays']], sla: $sla);
		self::assertSame('calendarDays', $rungs[0]['offsetUnit']);

		foreach ([['trigger' => 'onBreach', 'offset' => 1, 'offsetUnit' => 'hours'], ['trigger' => 'preBreach', 'offset' => -1, 'offsetUnit' => 'hours'], ['trigger' => 'preBreach', 'offset' => 1, 'offsetUnit' => 'weeks'], ['trigger' => 'preBreach', 'offset' => 1, 'offsetUnit' => 'hours', 'priority' => 'urgent'], ['trigger' => 'preBreach', 'offset' => 1, 'offsetUnit' => 'hours', 'notifyRole' => [1]]] as $bad) {
			try {
				$this->ladder->normaliseRules(rules: [$bad], sla: $sla);
				self::fail('accepted ' . json_encode($bad));
			} catch (FlowTimerValidationException $refused) {
				self::assertStringContainsString('Escalation rule #0', $refused->getMessage());
			}
		}
	}//end testOffsetUnitAcceptsCalendarDaysAndRefusesTheRest()

	public function testAShortWarningOnALongerSlaIsAcceptedAcrossUnits(): void {
		// SLA 2 calendarDays, preBreach 24 hours: 24h is inside 2 days. The raw
		// integer comparison (24 > 2) would have refused this.
		$anchor = $this->at('2026-09-03 10:00');
		$fireAt = (new SlaCalculator())->add(from: $anchor, value: 2, unit: 'calendarDays', calendar: $this->calendar);
		$rungs = $this->ladder->normaliseRules(rules: [['trigger' => 'preBreach', 'offset' => 24, 'offsetUnit' => 'hours']], sla: ['value' => 2, 'unit' => 'calendarDays']);
		$this->ladder->validateAgainstTimeline(rungs: $rungs, anchorAt: $anchor, fireAt: $fireAt, calendar: $this->calendar);
		self::assertSame('2026-09-04 10:00', $this->ladder->rungInstant(rung: $rungs[0], fireAt: $fireAt, calendar: $this->calendar)->format('Y-m-d H:i'));
	}//end testAShortWarningOnALongerSlaIsAcceptedAcrossUnits()

	public function testALongWarningOnAShorterSlaIsRefusedAcrossUnits(): void {
		// SLA 48 hours, preBreach 5 businessDays: the raw comparison (5 > 48 is
		// false) would have ACCEPTED this.
		$anchor = $this->at('2026-09-03 10:00');
		$fireAt = (new SlaCalculator())->add(from: $anchor, value: 48, unit: 'hours', calendar: $this->calendar);
		$rungs = $this->ladder->normaliseRules(rules: [['trigger' => 'preBreach', 'offset' => 5, 'offsetUnit' => 'businessDays']], sla: ['value' => 48, 'unit' => 'hours']);
		$this->expectException(FlowTimerValidationException::class);
		$this->expectExceptionMessage('exceeds the SLA');
		$this->ladder->validateAgainstTimeline(rungs: $rungs, anchorAt: $anchor, fireAt: $fireAt, calendar: $this->calendar);
	}//end testALongWarningOnAShorterSlaIsRefusedAcrossUnits()

	public function testDueRungsFireEveryPassedRungInLadderOrderOnceEach(): void {
		$rungs = $this->ladder->resolveLadder(timer: $this->timer())['rungs'];
		$fireAt = $this->at('2026-09-20 12:00');
		// Deadline 5 days away: the 14-day and 7-day rungs have both passed unfired.
		$now = $this->at('2026-09-15 12:00');
		$due = $this->ladder->dueRungs(rungs: $rungs, fireAt: $fireAt, now: $now, firedKeys: [], calendar: $this->calendar);
		self::assertSame(['preBreach:14:calendarDays', 'preBreach:7:calendarDays'], array_map(static fn (array $entry): string => $entry['rung']['key'], $due));
		self::assertSame('2026-09-06 12:00', $due[0]['at']->format('Y-m-d H:i'));

		// Yesterday's 7-day rung fired: today, still more than 2 days out, nothing is due.
		$none = $this->ladder->dueRungs(rungs: $rungs, fireAt: $fireAt, now: $now, firedKeys: ['preBreach:14:calendarDays', 'preBreach:7:calendarDays'], calendar: $this->calendar);
		self::assertSame([], $none);

		// next_rung_at is the earliest unfired rung: the 2-day one.
		$next = $this->ladder->nextRungAt(rungs: $rungs, fireAt: $fireAt, firedKeys: ['preBreach:14:calendarDays', 'preBreach:7:calendarDays'], calendar: $this->calendar);
		self::assertSame('2026-09-18 12:00', $next->format('Y-m-d H:i'));
		self::assertNull($this->ladder->nextRungAt(rungs: $rungs, fireAt: $fireAt, firedKeys: array_column($rungs, 'key'), calendar: $this->calendar));
	}//end testDueRungsFireEveryPassedRungInLadderOrderOnceEach()

	public function testHandlerResolvesToTheAssigneeUserOrGroup(): void {
		$rung = $this->ladder->resolveLadder(timer: $this->timer())['rungs'][1];

		$user = new Task();
		$user->setPerformerType(Task::PERFORMER_USER);
		$user->setAssignee('alice');
		self::assertSame(
			[['type' => 'user', 'id' => 'alice', 'role' => 'handler'], ['type' => 'role', 'id' => 'teamleader', 'role' => 'teamleader']],
			$this->ladder->resolveRecipients(rung: $rung, subject: $user, roleBindings: [])
		);

		// The GROUP path: a group performer's assignee is a gid.
		$group = new Task();
		$group->setPerformerType(Task::PERFORMER_GROUP);
		$group->setAssignee('vergunningen');
		self::assertSame(
			[['type' => 'group', 'id' => 'vergunningen', 'role' => 'handler'], ['type' => 'group', 'id' => 'teamleaders', 'role' => 'teamleader']],
			$this->ladder->resolveRecipients(rung: $rung, subject: $group, roleBindings: ['teamleader' => 'group:teamleaders'])
		);
	}//end testHandlerResolvesToTheAssigneeUserOrGroup()

	public function testUnassignedHandlerFallsBackToCandidateGroupsThenUsersThenRole(): void {
		$rung = $this->ladder->resolveLadder(timer: $this->timer())['rungs'][0];

		$pooled = new Task();
		$pooled->setPerformerType(Task::PERFORMER_USER);
		$pooled->setCandidateGroups(['team-a', 'team-b']);
		$pooled->setCandidateUsers(['bob']);
		self::assertSame(
			[['type' => 'group', 'id' => 'team-a', 'role' => 'handler'], ['type' => 'group', 'id' => 'team-b', 'role' => 'handler']],
			$this->ladder->resolveRecipients(rung: $rung, subject: $pooled, roleBindings: [])
		);

		$users = new Task();
		$users->setPerformerType(Task::PERFORMER_USER);
		$users->setCandidateUsers(['bob', 'carol']);
		self::assertSame(
			[['type' => 'user', 'id' => 'bob', 'role' => 'handler'], ['type' => 'user', 'id' => 'carol', 'role' => 'handler']],
			$this->ladder->resolveRecipients(rung: $rung, subject: $users, roleBindings: [])
		);

		self::assertSame([['type' => 'role', 'id' => 'handler', 'role' => 'handler']], $this->ladder->resolveRecipients(rung: $rung, subject: null, roleBindings: []));
	}//end testUnassignedHandlerFallsBackToCandidateGroupsThenUsersThenRole()

	public function testSlaBreachedRungFallsAfterTheDeadline(): void {
		$rung = $this->ladder->normaliseRules(rules: [['trigger' => 'slaBreached', 'offset' => 2, 'offsetUnit' => 'calendarDays']], sla: ['value' => 5, 'unit' => 'calendarDays'])[0];
		self::assertSame('slaBreached:2:calendarDays', $rung['key']);
		self::assertSame('2026-09-22 12:00', $this->ladder->rungInstant(rung: $rung, fireAt: $this->at('2026-09-20 12:00'), calendar: $this->calendar)->format('Y-m-d H:i'));
		// A slaBreached rung is never checked against the anchor.
		$this->ladder->validateAgainstTimeline(rungs: [$rung], anchorAt: $this->at('2026-09-19 12:00'), fireAt: $this->at('2026-09-20 12:00'), calendar: $this->calendar);
		self::assertInstanceOf(DateTime::class, new DateTime());
	}//end testSlaBreachedRungFallsAfterTheDeadline()
}//end class
