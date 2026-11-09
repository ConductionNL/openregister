<?php

/**
 * Golden test for EscalationLadderService: ladder resolution, rule
 * normalisation (accepted shapes and every refusal message), the timeline
 * check, rung instants, next/due rungs and recipient descriptors. Every
 * instant is fixed; nothing reads the clock. Re-record with UPDATE_GOLDEN=1.
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

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Timer;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Service\Flow\Timer\EscalationLadderService;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerDefinitionStore;
use OCA\OpenRegister\Service\Flow\Timer\SlaCalculator;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Timer\EscalationLadderService
 * @uses \OCA\OpenRegister\Db\FlowTimer
 * @uses \OCA\OpenRegister\Db\Task
 * @uses \OCA\OpenRegister\Service\Flow\Timer\SlaCalculator
 * @uses \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar
 * @uses \OCA\OpenRegister\Service\Flow\Timer\ServiceHours
 * @uses \OCA\OpenRegister\Service\Flow\Timer\SlaDeclaration
 * @covers \OCA\OpenRegister\Service\Flow\Timer\EscalationRule
 */
class EscalationLadderGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/escalation-ladder-golden.json';

	private EscalationLadderService $ladder;

	private WorkingCalendar $calendar;

	protected function setUp(): void {
		parent::setUp();
		$ladders = EscalationLadderServiceTest::seededLadders();
		$ladders['bad-bindings'] = ['slug' => 'bad-bindings', 'rungs' => $ladders['nl-termijn-default']['rungs'], 'roleBindings' => 'x'];
		$ladders['no-rungs'] = ['slug' => 'no-rungs'];
		$definitions = $this->createMock(FlowTimerDefinitionStore::class);
		$definitions->method('ladders')->willReturn($ladders);
		$this->ladder = new EscalationLadderService(definitions: $definitions, calculator: new SlaCalculator());
		$this->calendar = WorkingCalendar::fromArray(definition: WorkingCalendarTest::nlNational());
	}//end setUp()

	private static function at(string $when): DateTimeImmutable {
		return new DateTimeImmutable($when, new DateTimeZone('Europe/Amsterdam'));
	}//end at()

	private static function timer(?string $slug, mixed $rules, ?string $organisation): FlowTimer {
		$timer = new FlowTimer();
		$timer->setUuid('t-1');
		$timer->setBudgetValue(56.4);
		$timer->setBudgetUnit('calendarDays');
		$timer->setLadderSlug($slug);
		$timer->setEscalationRules($rules);
		$timer->setOrganisation($organisation);

		return $timer;
	}//end timer()

	private static function task(?string $assignee, ?string $performerType, ?array $groups, ?array $users): Task {
		$task = new Task();
		$task->setAssignee($assignee);
		$task->setPerformerType($performerType);
		$task->setCandidateGroups($groups);
		$task->setCandidateUsers($users);

		return $task;
	}//end task()

	private static function capture(Closure $call): mixed {
		try {
			return $call();
		} catch (Throwable $error) {
			return ['error' => get_class($error), 'message' => $error->getMessage()];
		}
	}//end capture()

	private static function instants(mixed $value): mixed {
		if ($value instanceof DateTimeInterface) {
			return $value->format('Y-m-d H:i:s T');
		}

		if (is_array($value) === true) {
			return array_map(static fn (mixed $item): mixed => self::instants($item), $value);
		}

		return $value;
	}//end instants()

	/**
	 * @return array<string, array{0: mixed, 1: array{value: int, unit: string}|null}>
	 */
	private static function ruleCases(): array {
		$sla = ['value' => 10, 'unit' => 'businessDays'];
		$ok = ['trigger' => 'preBreach', 'offset' => 2, 'offsetUnit' => 'businessDays'];
		return [
			'not array' => ['x', $sla],
			'empty' => [[], null],
			'no sla' => [[$ok], null],
			'rule not object' => [[$ok, 'x'], $sla],
			'rule string key' => [['a' => 'x'], $sla],
			'minimal' => [[$ok], $sla],
			'full' => [[['key' => ' k1 ', 'trigger' => 'slaBreached', 'offset' => 0, 'offsetUnit' => 'hours', 'notifyRole' => ' handler ', 'escalateToRole' => ['manager', ' manager', 'boss'], 'priority' => 'critical', 'message' => ' hi ', 'consequence' => '  ', 'openIncident' => true]], $sla],
			'postBreach' => [[['trigger' => 'postBreach', 'offset' => 3, 'offsetUnit' => 'calendarDays', 'openIncident' => 1, 'message' => 5]], $sla],
			'bad trigger' => [[['trigger' => 'soon', 'offset' => 1, 'offsetUnit' => 'hours']], $sla],
			'no trigger' => [[['offset' => 1, 'offsetUnit' => 'hours']], $sla],
			'negative offset' => [[['trigger' => 'preBreach', 'offset' => -1, 'offsetUnit' => 'hours']], $sla],
			'string offset' => [[['trigger' => 'preBreach', 'offset' => '1', 'offsetUnit' => 'hours']], $sla],
			'float offset' => [[['trigger' => 'preBreach', 'offset' => 1.5, 'offsetUnit' => 'hours']], $sla],
			'bad unit' => [[['trigger' => 'preBreach', 'offset' => 1, 'offsetUnit' => 'weeks']], $sla],
			'no unit' => [[['trigger' => 'preBreach', 'offset' => 1]], $sla],
			'bad priority' => [[$ok + ['priority' => 'urgent']], $sla],
			'role not list' => [[$ok + ['notifyRole' => 5]], $sla],
			'role empty entry' => [[$ok + ['escalateToRole' => ['a', ' ']]], $sla],
			'role non-string entry' => [[$ok + ['notifyRole' => ['a', 3]]], $sla],
			'second rule bad' => [[$ok, ['trigger' => 'preBreach', 'offset' => 1, 'offsetUnit' => 'x']], $sla],
			'fault order: trigger before offset' => [[['trigger' => 'x', 'offset' => -1, 'offsetUnit' => 'x', 'priority' => 'x']], $sla],
			'fault order: offset before unit' => [[['trigger' => 'preBreach', 'offset' => -1, 'offsetUnit' => 'x', 'priority' => 'x']], $sla],
			'fault order: unit before priority' => [[['trigger' => 'preBreach', 'offset' => 1, 'offsetUnit' => 'x', 'priority' => 'x']], $sla],
			'fault order: priority before roles' => [[['trigger' => 'preBreach', 'offset' => 1, 'offsetUnit' => 'hours', 'priority' => 'x', 'notifyRole' => 5]], $sla],
			'fault order: notify before escalate' => [[$ok + ['notifyRole' => 5, 'escalateToRole' => 5]], $sla],
		];
	}//end ruleCases()

	public function testLadderMatchesGolden(): void {
		$actual = [];
		$timers = [
			'inline rules win' => ['nl-termijn-default', [['trigger' => 'preBreach', 'offset' => 1, 'offsetUnit' => 'hours']], 'org-1'],
			'named ladder' => ['nl-termijn-default', null, null],
			'named ladder padded' => ['  org-ladder  ', [], null],
			'organisation default' => [null, null, ' org-1 '],
			'organisation unknown' => ['', null, 'org-2'],
			'nothing named' => [null, null, null],
			'unknown ladder' => ['nope', null, null],
			'bindings not array' => ['bad-bindings', null, null],
			'ladder without rungs' => ['no-rungs', null, null],
			'inline rules not array' => [null, 'x', null],
		];
		foreach ($timers as $name => [$slug, $rules, $organisation]) {
			$timer = self::timer($slug, $rules, $organisation);
			$actual['resolveLadder / ' . $name] = self::capture(fn () => $this->ladder->resolveLadder(timer: $timer));
		}

		foreach (self::ruleCases() as $name => [$rules, $sla]) {
			$actual['normaliseRules / ' . $name] = self::capture(fn () => $this->ladder->normaliseRules(rules: $rules, sla: $sla));
		}

		$rungs = $this->ladder->resolveLadder(timer: self::timer('nl-termijn-default', null, null))['rungs'];
		$rungs[] = ['key' => 'tie', 'trigger' => 'postBreach', 'offset' => 0, 'offsetUnit' => 'hours', 'notifyRole' => [], 'escalateToRole' => []];
		$fireAt = self::at('2026-06-30 17:00:00');
		$anchors = ['anchor early' => '2026-05-01 09:00:00', 'anchor late' => '2026-06-25 09:00:00', 'anchor exact' => '2026-06-30 17:00:00'];
		foreach ($anchors as $name => $anchor) {
			$actual['validateAgainstTimeline / ' . $name] = self::capture(fn () => $this->ladder->validateAgainstTimeline(rungs: $rungs, anchorAt: self::at($anchor), fireAt: $fireAt, calendar: $this->calendar) ?? 'passed');
		}

		foreach ($rungs as $rung) {
			$actual['rungInstant / ' . $rung['key']] = self::instants($this->ladder->rungInstant(rung: $rung, fireAt: $fireAt, calendar: $this->calendar));
		}

		$fired = ['none' => [], 'first two' => [$rungs[0]['key'], $rungs[1]['key']], 'all' => array_column($rungs, 'key')];
		foreach ($fired as $name => $keys) {
			$actual['nextRungAt / ' . $name] = self::instants($this->ladder->nextRungAt(rungs: $rungs, fireAt: $fireAt, firedKeys: $keys, calendar: $this->calendar));
			foreach (['2026-06-01 00:00:00', '2026-06-29 12:00:00', '2026-06-30 17:00:00', '2026-08-01 00:00:00'] as $now) {
				$actual['dueRungs / ' . $name . ' / ' . $now] = self::instants($this->ladder->dueRungs(timeline: new \OCA\OpenRegister\Service\Flow\Timer\RungTimeline(rungs: $rungs, fireAt: $fireAt, firedKeys: $keys, calendar: $this->calendar), now: self::at($now)));
			}
		}

		$rung = ['notifyRole' => ['handler', 'teamleader'], 'escalateToRole' => ['manager', 'handler', 'odd']];
		$bindings = ['teamleader' => ' group : leads ', 'odd' => 'no-colon', 'manager' => 'user:boss:x'];
		$subjects = [
			'no subject' => null,
			'assignee user' => self::task(' alice ', 'user', ['g1'], ['u1']),
			'assignee group performer' => self::task('team', 'group', null, null),
			'assignee no performer type' => self::task('bob', null, null, null),
			'candidate groups' => self::task('', 'user', ['g1', 'g2'], ['u1']),
			'candidate users' => self::task(null, 'user', [], ['u1', 'u2']),
			'nobody' => self::task(null, 'user', null, null),
		];
		foreach ($subjects as $name => $subject) {
			$actual['resolveRecipients / ' . $name] = $this->ladder->resolveRecipients(rung: $rung, subject: $subject, roleBindings: $bindings);
			$actual['resolveRecipients / ' . $name . ' / bound handler'] = $this->ladder->resolveRecipients(rung: $rung, subject: $subject, roleBindings: ['handler' => 'group:desk']);
		}

		$actual = json_decode((string)json_encode($actual), true);
		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testLadderMatchesGolden()
}//end class
