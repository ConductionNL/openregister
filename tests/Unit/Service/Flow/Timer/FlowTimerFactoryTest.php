<?php

/**
 * FlowTimerFactory: every arm-time refusal, the defaults a new timer starts
 * with, the anchor offset, and the successor of a supersession.
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
use OCA\OpenRegister\Exception\FlowTimerValidationException;
use OCA\OpenRegister\Service\Flow\Timer\EscalationLadderService;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerAct;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerDefinitionStore;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerArithmetic;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerFactory;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerScope;
use OCA\OpenRegister\Service\Flow\Timer\SlaCalculator;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Timer\FlowTimerFactory
 * @uses \OCA\OpenRegister\Service\Flow\Timer\FlowTimerAct
 * @uses \OCA\OpenRegister\Service\Flow\Timer\FlowTimerScope
 * @uses \OCA\OpenRegister\Service\Flow\Timer\FlowTimerValues
 * @uses \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarService
 * @uses \OCA\OpenRegister\Service\Flow\Timer\EscalationLadderService
 * @uses \OCA\OpenRegister\Service\Flow\Timer\SlaCalculator
 * @uses \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar
 * @uses \OCA\OpenRegister\Db\FlowTimer
 * @uses \OCA\OpenRegister\Db\TypedFieldsJson
 * @uses \OCA\OpenRegister\Service\Flow\Timer\SlaDeclaration
 * @uses \OCA\OpenRegister\Service\Flow\Timer\MomentShift
 * @uses \OCA\OpenRegister\Service\Flow\Timer\ServiceHours
 * @uses \OCA\OpenRegister\Service\Flow\Timer\SlaAddition
 * @uses \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarDefinition
 * @uses \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarHolidays
 * @uses \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarRules
 * @uses \OCA\OpenRegister\Service\Flow\Timer\EscalationRule
 */
class FlowTimerFactoryTest extends TestCase {

	private DateTimeZone $tz;

	private WorkingCalendarService $calendars;

	private FlowTimerFactory $factory;

	protected function setUp(): void {
		parent::setUp();
		$this->tz = new DateTimeZone('Europe/Amsterdam');
		$definitions = $this->createMock(FlowTimerDefinitionStore::class);
		$definitions->method('calendars')->willReturn(self::calendars());
		$definitions->method('ladders')->willReturn([]);
		$calculator = new SlaCalculator();
		$this->calendars = new WorkingCalendarService(definitions: $definitions);
		$this->factory = new FlowTimerFactory(
			new FlowTimerArithmetic($this->calendars, $calculator, new EscalationLadderService(definitions: $definitions, calculator: $calculator))
		);
	}//end setUp()

	/**
	 * The seeded working calendars.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function calendars(): array {
		$data = json_decode((string)file_get_contents(__DIR__ . '/../../../../../lib/Settings/flow_timer_register.json'), true);
		$calendars = [];
		foreach ($data['components']['objects'] as $object) {
			if (($object['@self']['schema'] ?? '') === 'working-calendar') {
				unset($object['@self']);
				$calendars[$object['slug']] = $object;
			}
		}

		return $calendars;
	}//end calendars()

	private function at(string $when): DateTimeImmutable {
		return new DateTimeImmutable($when, $this->tz);
	}//end at()

	/**
	 * @param array<string, mixed> $overrides
	 *
	 * @return array<string, mixed>
	 */
	private function config(array $overrides = []): array {
		return array_merge(
			[
				'subjectType' => 'task',
				'subjectUuid' => 'task-1',
				'sla' => ['value' => 10, 'unit' => 'calendarDays'],
			],
			$overrides
		);
	}//end config()

	private function act(?DateTimeImmutable $when = null): FlowTimerAct {
		return new FlowTimerAct(actor: 'alice', now: $this->at('2026-03-02 09:00:00'), when: $when);
	}//end act()

	public function testBuildAppliesTheDefaultsOfANewTimer(): void {
		$timer = $this->factory->build(config: $this->config(), act: $this->act());

		self::assertSame('task', $timer->getSubjectType());
		self::assertSame('task-1', $timer->getSubjectUuid());
		self::assertSame(FlowTimer::PURPOSE_DUE, $timer->getPurpose());
		self::assertSame(FlowTimer::LEGAL_NONE, $timer->getLegalEffect());
		self::assertNull($timer->getOnExpiry());
		self::assertSame(10.0, (float)$timer->getBudgetValue());
		self::assertSame('calendarDays', $timer->getBudgetUnit());
		self::assertSame('none', $timer->getRollToWorkingDay());
		self::assertSame(0.0, (float)$timer->getConsumedValue());
		self::assertSame(FlowTimer::STATE_ARMED, $timer->getState());
		self::assertFalse((bool)$timer->getBreached());
		self::assertSame(0, $timer->getExtensionCount());
		self::assertSame(1, $timer->getExtensionMax());
		self::assertSame(0, $timer->getSuspendedTotalSeconds());
		self::assertSame('alice', $timer->getCreatedBy());
		self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-/', (string)$timer->getUuid());
		self::assertSame([], $timer->getEscalationRules());
		// Without an anchorEventAt the clock anchors the timer, and it runs from there.
		self::assertSame('2026-03-02T09:00:00+01:00', $timer->getAnchorAt()->format('c'));
		self::assertSame('2026-03-02T09:00:00+01:00', $timer->getRunningSince()->format('c'));
		self::assertNull($timer->getAnchorOffset());
		self::assertNull($timer->getAnchorOffsetUnit());
		self::assertNull($timer->getAnchorEvent());
	}//end testBuildAppliesTheDefaultsOfANewTimer()

	public function testBuildCarriesTheProvenanceAndClampsANegativeExtensionMax(): void {
		$timer = $this->factory->build(
			config: $this->config([
				'organisation' => 'org-1',
				'runUuid' => 'run-1',
				'nodeId' => 'node-1',
				'appId' => 'procest',
				'title' => 'Besluittermijn',
				'metadata' => ['zaak' => 'Z-1'],
				'calendar' => 'nl-national',
				'ladder' => 'standard',
				'extensionMax' => -3,
			]),
			act: $this->act()
		);

		self::assertSame('org-1', $timer->getOrganisation());
		self::assertSame('run-1', $timer->getRunUuid());
		self::assertSame('node-1', $timer->getNodeId());
		self::assertSame('procest', $timer->getAppId());
		self::assertSame('Besluittermijn', $timer->getTitle());
		self::assertSame(['zaak' => 'Z-1'], $timer->getMetadata());
		self::assertSame('nl-national', $timer->getCalendarSlug());
		self::assertSame('standard', $timer->getLadderSlug());
		self::assertSame(0, $timer->getExtensionMax());
	}//end testBuildCarriesTheProvenanceAndClampsANegativeExtensionMax()

	public function testBuildKeepsTheRollTheSlaAskedFor(): void {
		$timer = $this->factory->build(
			config: $this->config(['sla' => ['value' => 5, 'unit' => 'businessDays', 'rollToWorkingDay' => 'next']]),
			act: $this->act()
		);

		self::assertSame('next', $timer->getRollToWorkingDay());
		self::assertSame('businessDays', $timer->getBudgetUnit());
	}//end testBuildKeepsTheRollTheSlaAskedFor()

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function refusedSubjects(): array {
		return [
			'unknown subject type' => [['subjectType' => 'zaak'], "got 'zaak' / 'task-1'"],
			'missing subject type' => [['subjectType' => null], "got '' / 'task-1'"],
			'blank subject uuid' => [['subjectUuid' => '   '], "got 'task' / ''"],
		];
	}//end refusedSubjects()

	#[DataProvider('refusedSubjects')]
	public function testBuildRefusesAnInvalidSubject(array $overrides, string $fragment): void {
		$this->expectException(FlowTimerValidationException::class);
		$this->expectExceptionMessage($fragment);

		$this->factory->build(config: $this->config($overrides), act: $this->act());
	}//end testBuildRefusesAnInvalidSubject()

	public function testBuildTrimsTheSubjectUuid(): void {
		$timer = $this->factory->build(config: $this->config(['subjectUuid' => '  obj-9 ', 'subjectType' => 'object']), act: $this->act());

		self::assertSame('obj-9', $timer->getSubjectUuid());
		self::assertSame('object', $timer->getSubjectType());
	}//end testBuildTrimsTheSubjectUuid()

	public function testBuildRefusesAnUnknownPurpose(): void {
		$this->expectException(FlowTimerValidationException::class);
		$this->expectExceptionMessage("Timer purpose 'reminder' is refused");

		$this->factory->build(config: $this->config(['purpose' => 'reminder']), act: $this->act());
	}//end testBuildRefusesAnUnknownPurpose()

	public function testBuildRefusesAnUnknownLegalEffect(): void {
		$this->expectException(FlowTimerValidationException::class);
		$this->expectExceptionMessage("Timer legalEffect 'binding' is refused");

		$this->factory->build(config: $this->config(['legalEffect' => 'binding']), act: $this->act());
	}//end testBuildRefusesAnUnknownLegalEffect()

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function enforcingOutcomes(): array {
		return [
			'skip' => ['skip'],
			'error' => ['error'],
			'dead letter' => ['dead_letter'],
			'transition' => ['transition:reject'],
		];
	}//end enforcingOutcomes()

	#[DataProvider('enforcingOutcomes')]
	public function testAWettelijkExpiryTimerKeepsItsOutcome(string $outcome): void {
		$timer = $this->factory->build(
			config: $this->config(['purpose' => 'expiry', 'legalEffect' => 'wettelijk', 'onExpiry' => $outcome]),
			act: $this->act()
		);

		self::assertSame(FlowTimer::PURPOSE_EXPIRY, $timer->getPurpose());
		self::assertSame(FlowTimer::LEGAL_WETTELIJK, $timer->getLegalEffect());
		self::assertSame($outcome, $timer->getOnExpiry());
	}//end testAWettelijkExpiryTimerKeepsItsOutcome()

	public function testAnOutcomeOutsideTheVocabularyIsRefused(): void {
		$this->expectException(FlowTimerValidationException::class);
		$this->expectExceptionMessage("onExpiry 'close' is refused: use skip, error, dead_letter or transition:<action>.");

		$this->factory->build(
			config: $this->config(['purpose' => 'expiry', 'legalEffect' => 'wettelijk', 'onExpiry' => 'close']),
			act: $this->act()
		);
	}//end testAnOutcomeOutsideTheVocabularyIsRefused()

	public function testAnOutcomeOnADueTimerIsRefused(): void {
		$this->expectException(FlowTimerValidationException::class);
		$this->expectExceptionMessage("onExpiry 'skip' is refused on a 'due' timer: only an expiry timer enforces.");

		$this->factory->build(
			config: $this->config(['purpose' => 'due', 'legalEffect' => 'wettelijk', 'onExpiry' => 'skip']),
			act: $this->act()
		);
	}//end testAnOutcomeOnADueTimerIsRefused()

	public function testAnOutcomeOnAnAdvisoryExpiryTimerIsRefused(): void {
		$this->expectException(FlowTimerValidationException::class);
		$this->expectExceptionMessage("legal effect 'servicenorm' is advisory");

		$this->factory->build(
			config: $this->config(['purpose' => 'expiry', 'legalEffect' => 'servicenorm', 'onExpiry' => 'skip']),
			act: $this->act()
		);
	}//end testAnOutcomeOnAnAdvisoryExpiryTimerIsRefused()

	public function testAnAnchorEventAtAnchorsTheTimerInsteadOfTheClock(): void {
		$timer = $this->factory->build(
			config: $this->config(['anchorEventAt' => '2026-02-20T10:30:00+01:00', 'anchorEvent' => 'ontvangst']),
			act: $this->act()
		);

		self::assertSame('2026-02-20T10:30:00+01:00', $timer->getAnchorAt()->format('c'));
		self::assertSame('2026-02-20T10:30:00+01:00', $timer->getRunningSince()->format('c'));
		self::assertSame('ontvangst', $timer->getAnchorEvent());
	}//end testAnAnchorEventAtAnchorsTheTimerInsteadOfTheClock()

	public function testAnOffsetDefaultsToCalendarDaysAndMovesTheAnchor(): void {
		$timer = $this->factory->build(
			config: $this->config(['anchorEventAt' => '2026-03-06T10:00:00+01:00', 'anchorOffset' => 2]),
			act: $this->act()
		);

		// Friday + 2 calendar days = Sunday: no working-day logic for calendar days.
		self::assertSame('2026-03-08T10:00:00+01:00', $timer->getAnchorAt()->format('c'));
		self::assertSame('2026-03-08T10:00:00+01:00', $timer->getRunningSince()->format('c'));
		self::assertSame(2, $timer->getAnchorOffset());
		self::assertSame('calendarDays', $timer->getAnchorOffsetUnit());
	}//end testAnOffsetDefaultsToCalendarDaysAndMovesTheAnchor()

	public function testABusinessDayOffsetIsCountedOnTheTimersCalendar(): void {
		$timer = $this->factory->build(
			config: $this->config([
				'anchorEventAt' => '2026-03-06T10:00:00+01:00',
				'anchorOffset' => 1,
				'anchorOffsetUnit' => 'businessDays',
			]),
			act: $this->act()
		);

		// Friday + 1 business day skips the weekend.
		self::assertSame('2026-03-09', $timer->getAnchorAt()->format('Y-m-d'));
		self::assertSame('businessDays', $timer->getAnchorOffsetUnit());
	}//end testABusinessDayOffsetIsCountedOnTheTimersCalendar()

	public function testANonIntegerOffsetIsRefused(): void {
		$this->expectException(FlowTimerValidationException::class);
		$this->expectExceptionMessage("anchorOffset '1.5' must be an integer.");

		$this->factory->build(config: $this->config(['anchorOffset' => 1.5]), act: $this->act());
	}//end testANonIntegerOffsetIsRefused()

	public function testAnUnknownOffsetUnitIsRefused(): void {
		$this->expectException(FlowTimerValidationException::class);

		$this->factory->build(config: $this->config(['anchorOffset' => 1, 'anchorOffsetUnit' => 'fortnights']), act: $this->act());
	}//end testAnUnknownOffsetUnitIsRefused()

	public function testEscalationRulesAreNormalisedAgainstTheSla(): void {
		$timer = $this->factory->build(
			config: $this->config([
				'escalationRules' => [['trigger' => 'preBreach', 'offset' => 2, 'offsetUnit' => 'calendarDays', 'notifyRole' => 'handler']],
			]),
			act: $this->act()
		);

		$rules = $timer->getEscalationRules();
		self::assertCount(1, $rules);
		self::assertSame('preBreach', $rules[0]['trigger']);
		self::assertSame(['handler'], $rules[0]['notifyRole']);
	}//end testEscalationRulesAreNormalisedAgainstTheSla()

	private function prior(?int $offset, ?string $unit): FlowTimer {
		$prior = $this->factory->build(
			config: $this->config([
				'purpose' => 'expiry',
				'legalEffect' => 'wettelijk',
				'onExpiry' => 'error',
				'title' => 'Hersteltermijn',
				'anchorEventAt' => '2026-03-02T09:00:00+01:00',
				'anchorOffset' => $offset,
				'anchorOffsetUnit' => $unit,
			]),
			act: $this->act()
		);
		$prior->setId(7);
		$prior->setConsumedValue(3.5);
		$prior->setState(FlowTimer::STATE_SUSPENDED);
		$prior->setSuspendedSince(new DateTime('2026-03-04 09:00:00', $this->tz));
		$prior->setSuspendReason('waiting');
		$prior->setFiredAt(new DateTime('2026-03-05 09:00:00', $this->tz));
		$prior->setCancelledAt(new DateTime('2026-03-05 09:00:00', $this->tz));
		$prior->setCancelReason('nope');
		$prior->setBreached(true);
		$prior->setCreated(new DateTime('2026-03-02 09:00:00', $this->tz));

		return $prior;
	}//end prior()

	public function testASuccessorInheritsTheTermButNotTheLifecycle(): void {
		$prior = $this->prior(offset: null, unit: null);
		$calendar = $this->calendars->resolve(calendarSlug: null, organisation: null);
		$now = $this->at('2026-03-10 12:00:00');

		$successor = $this->factory->successor(
			scope: new FlowTimerScope(timer: $prior, calendar: $calendar),
			act: new FlowTimerAct(actor: 'bob', now: $now, when: $this->at('2026-03-09 08:00:00'))
		);

		self::assertNotSame($prior->getUuid(), $successor->getUuid());
		self::assertSame($prior->getUuid(), $successor->getSupersedesUuid());
		self::assertNull($successor->getId());
		// Inherited fields.
		self::assertSame('Hersteltermijn', $successor->getTitle());
		self::assertSame('error', $successor->getOnExpiry());
		self::assertSame(FlowTimer::LEGAL_WETTELIJK, $successor->getLegalEffect());
		self::assertSame('task-1', $successor->getSubjectUuid());
		self::assertSame(3.5, (float)$successor->getConsumedValue());
		// Without an offset the new instant is the anchor.
		self::assertSame('2026-03-09T08:00:00+01:00', $successor->getAnchorAt()->format('c'));
		self::assertSame('2026-03-09T08:00:00+01:00', $successor->getRunningSince()->format('c'));
		self::assertSame('2026-03-10T12:00:00+01:00', $successor->getCreated()->format('c'));
		// Reset lifecycle.
		self::assertSame(FlowTimer::STATE_ARMED, $successor->getState());
		self::assertNull($successor->getSuspendedSince());
		self::assertNull($successor->getSuspendReason());
		self::assertNull($successor->getFiredAt());
		self::assertNull($successor->getCancelledAt());
		self::assertNull($successor->getCancelReason());
		self::assertFalse((bool)$successor->getBreached());
	}//end testASuccessorInheritsTheTermButNotTheLifecycle()

	public function testASuccessorReappliesThePredecessorsOffsetToTheNewInstant(): void {
		$prior = $this->prior(offset: 3, unit: 'calendarDays');
		$calendar = $this->calendars->resolve(calendarSlug: null, organisation: null);

		$successor = $this->factory->successor(
			scope: new FlowTimerScope(timer: $prior, calendar: $calendar),
			act: new FlowTimerAct(actor: 'bob', now: $this->at('2026-03-10 12:00:00'), when: $this->at('2026-03-09 08:00:00'))
		);

		self::assertSame('2026-03-12T08:00:00+01:00', $successor->getAnchorAt()->format('c'));
		self::assertSame(3, $successor->getAnchorOffset());
		self::assertSame('calendarDays', $successor->getAnchorOffsetUnit());
	}//end testASuccessorReappliesThePredecessorsOffsetToTheNewInstant()
}//end class
