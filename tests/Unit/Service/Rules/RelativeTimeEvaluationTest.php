<?php

/**
 * A `$age` condition is evaluated where rules are evaluated, counts in the
 * calendar the timers would resolve, and refuses when it cannot count
 * (task 3.2b).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rules
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rules;

use DateTime;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper\RelativeTimeCalendarCheck;
use OCA\OpenRegister\Exception\FlowTimerValidationException;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCA\OpenRegister\Service\Flow\Timer\SlaCalculator;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarService;
use OCA\OpenRegister\Service\Rules\AdministeredValidations;
use OCA\OpenRegister\Service\Rules\ConditionDialect;
use OCA\OpenRegister\Service\Rules\ConditionEvaluation;
use OCA\OpenRegister\Service\Rules\ConditionRefusedException;
use OCA\OpenRegister\Service\Rules\NamedConditionEvaluator;
use OCA\OpenRegister\Service\Rules\NamedConditionLibrary;
use OCA\OpenRegister\Service\Rules\RelativeTimeCondition;
use OCA\OpenRegister\Service\Rules\RelativeTimeEvaluation;
use OCA\OpenRegister\Tests\Unit\Service\Flow\Timer\WorkingCalendarTest;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class RelativeTimeEvaluationTest extends TestCase {

	private const CREATED_FRIDAY = ['createdAt' => '2026-09-11T16:30:00+02:00'];

	private WorkingCalendarService&MockObject $calendars;

	protected function setUp(): void {
		$this->calendars = $this->createMock(WorkingCalendarService::class);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function age(string $unit, ?string $calendar = null): array {
		$body = ['property' => 'createdAt', RelativeTimeCondition::MORE_THAN => ['value' => 3, 'unit' => $unit]];
		if ($calendar !== null) {
			$body['calendar'] = $calendar;
		}

		return [RelativeTimeCondition::KEY => $body];
	}

	private function time(string $now): RelativeTimeEvaluation {
		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('getDateTime')->willReturn(new DateTime($now));

		return new RelativeTimeEvaluation(new RelativeTimeCondition(new SlaCalculator()), $this->calendars, $clock);
	}

	private function evaluator(?RelativeTimeEvaluation $time): NamedConditionEvaluator {
		return new NamedConditionEvaluator(
			new ConditionDialect(ast: $this->createMock(CalculationEvaluator::class)),
			new NamedConditionLibrary(),
			$time
		);
	}

	private function nlNational(): WorkingCalendar {
		return WorkingCalendar::fromArray(definition: WorkingCalendarTest::nlNational());
	}

	public function testTheSpecsScenariosHoldThroughTheEvaluatorRulesUse(): void {
		$this->calendars->method('resolve')->willReturn($this->nlNational());
		$node = self::age(RelativeTimeCondition::UNIT_WORKING_HOURS);
		$evaluation = new ConditionEvaluation(document: self::CREATED_FRIDAY, library: [], depth: 0);

		$this->assertTrue($this->evaluator($this->time('2026-09-14T09:30:00+02:00'))->holds($node, $evaluation));
		$this->assertFalse($this->evaluator($this->time('2026-09-12T09:30:00+02:00'))->holds($node, $evaluation));
	}

	public function testItComposesInsideJunctionsAndNamedConditions(): void {
		$this->calendars->method('resolve')->willReturn($this->nlNational());
		$library = ['escalatie' => ['description' => 'd', 'expression' => self::age(RelativeTimeCondition::UNIT_WORKING_HOURS)]];
		$evaluation = new ConditionEvaluation(document: self::CREATED_FRIDAY, library: $library, depth: 0);
		$monday = $this->evaluator($this->time('2026-09-14T09:30:00+02:00'));

		$this->assertTrue($monday->holds(['$condition' => 'escalatie'], $evaluation));
		$this->assertFalse($monday->holds(['not' => [self::age(RelativeTimeCondition::UNIT_WORKING_HOURS)]], $evaluation));
	}

	public function testTheCalendarIsNamedElseTheOrganisationsElseTheDefault(): void {
		$this->calendars->expects($this->exactly(2))->method('resolve')
			->willReturnCallback(function (?string $calendarSlug, ?string $organisation): WorkingCalendar {
				static $calls = 0;
				$calls++;
				if ($calls === 1) {
					$this->assertSame(['nl-national', 'org-1'], [$calendarSlug, $organisation]);
				} else {
					$this->assertSame([null, 'org-1'], [$calendarSlug, $organisation]);
				}

				return $this->nlNational();
			});
		$evaluation = new ConditionEvaluation(document: self::CREATED_FRIDAY, library: [], depth: 0, organisation: 'org-1');
		$evaluator = $this->evaluator($this->time('2026-09-14T09:30:00+02:00'));

		$evaluator->holds(self::age(RelativeTimeCondition::UNIT_BUSINESS_DAYS, 'nl-national'), $evaluation);
		$evaluator->holds(self::age(RelativeTimeCondition::UNIT_BUSINESS_DAYS), $evaluation->deeper());
	}

	public function testWallClockUnitsResolveNoCalendar(): void {
		$this->calendars->expects($this->never())->method('resolve');

		$this->assertTrue($this->time('2026-09-11T20:00:00+02:00')->holds(self::age(RelativeTimeCondition::UNIT_HOURS), self::CREATED_FRIDAY));
	}

	public function testACalendarThatDoesNotResolveRefusesEvenUnderNegation(): void {
		$this->calendars->method('resolve')->willThrowException(new FlowTimerValidationException('Unknown working calendar "gemeente-x".'));

		try {
			$this->evaluator($this->time('2026-09-14T09:30:00+02:00'))->holds(
				['not' => self::age(RelativeTimeCondition::UNIT_WORKING_HOURS, 'gemeente-x')],
				new ConditionEvaluation(document: self::CREATED_FRIDAY, library: [], depth: 0)
			);
			$this->fail('An uncountable deadline must refuse, not read as "not due".');
		} catch (ConditionRefusedException $refused) {
			$this->assertSame(RelativeTimeCondition::KEY, $refused->getConditionName());
			$this->assertStringContainsString('gemeente-x', $refused->getWhy());
		}
	}

	public function testWithoutTheTimeEvaluationAnAgeNodeRefuses(): void {
		$this->expectException(ConditionRefusedException::class);

		$this->evaluator(null)->holds(
			['not' => self::age(RelativeTimeCondition::UNIT_HOURS)],
			new ConditionEvaluation(document: self::CREATED_FRIDAY, library: [], depth: 0)
		);
	}

	public function testTheSaveRefusesAMissingCalendarNamingItAndTheRule(): void {
		$this->calendars->method('resolve')->willThrowException(new FlowTimerValidationException('Unknown working calendar "gemeente-x".'));
		$schema = new Schema();
		$schema->setSlug('zaak');
		$schema->setConfiguration([AdministeredValidations::ANNOTATION => ['te-laat' => [
			'severity' => 'warn',
			'message' => 'Te laat',
			'condition' => self::age(RelativeTimeCondition::UNIT_WORKING_HOURS, 'gemeente-x'),
		]]]);

		try {
			(new RelativeTimeCalendarCheck(time: fn (): RelativeTimeEvaluation => $this->time('2026-09-14T09:30:00+02:00')))->check($schema);
			$this->fail('A calendar that does not resolve must be refused at save.');
		} catch (\Exception $refused) {
			$this->assertStringContainsString('te-laat', $refused->getMessage());
			$this->assertStringContainsString('gemeente-x', $refused->getMessage());
		}
	}

	public function testTheSaveAcceptsAResolvableCalendarAndSkipsWithoutTheResolution(): void {
		$this->calendars->method('resolve')->willReturn($this->nlNational());
		$schema = new Schema();
		$schema->setConfiguration([NamedConditionLibrary::ANNOTATION => [
			'escalatie' => ['description' => 'd', 'expression' => self::age(RelativeTimeCondition::UNIT_WORKING_HOURS)],
		]]);

		(new RelativeTimeCalendarCheck(time: $this->time('2026-09-14T09:30:00+02:00')))->check($schema);
		(new RelativeTimeCalendarCheck(time: null))->check($schema);
		$this->assertSame('', $this->time('2026-09-14T09:30:00+02:00')->refusalAtSave(self::age(RelativeTimeCondition::UNIT_WORKING_HOURS)) ?? '');
	}
}
