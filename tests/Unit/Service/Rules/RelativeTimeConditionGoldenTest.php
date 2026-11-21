<?php

/**
 * Golden test for {@see \OCA\OpenRegister\Service\Rules\RelativeTimeCondition}.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rules
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Rules;

use DateTimeImmutable;
use OCA\OpenRegister\Service\Flow\Timer\SlaCalculator;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar;
use OCA\OpenRegister\Service\Rules\RelativeTimeCheck;
use OCA\OpenRegister\Service\Rules\RelativeTimeCondition;
use OCA\OpenRegister\Service\Rules\RelativeTimeMoment;
use OCA\OpenRegister\Tests\Unit\Service\Flow\Timer\WorkingCalendarTest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Each case runs refusalFor(), compile() and holds() on one node, at a fixed
 * moment, with and without the shipped calendar; the outcomes are compared
 * with fixtures/relative-time-golden.json. Set UPDATE_GOLDEN=1 to rewrite it.
 */
class RelativeTimeConditionGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/relative-time-golden.json';

	private const NOW = '2026-09-14T09:30:00+02:00';

	/**
	 * @return array<string, array{0: mixed, 1: array<string, mixed>}>
	 */
	public static function cases(): array {
		$k = RelativeTimeCondition::KEY;
		$doc = ['createdAt' => '2026-09-11T16:30:00+02:00'];
		$at = static fn (string $comparison, mixed $value, string $unit): array => [$k => ['property' => 'createdAt', $comparison => ['value' => $value, 'unit' => $unit]]];
		$cases = [
			'plain node' => [['status' => 'open'], $doc],
			'scalar' => ['x', $doc],
			'declaration scalar' => [[$k => 'x'], $doc],
			'no property' => [[$k => ['moreThan' => ['value' => 1, 'unit' => 'hours']]], $doc],
			'empty property' => [[$k => ['property' => '', 'moreThan' => ['value' => 1, 'unit' => 'hours']]], $doc],
			'no comparison' => [[$k => ['property' => 'createdAt']], $doc],
			'offset scalar' => [[$k => ['property' => 'createdAt', 'lessThan' => 5]], $doc],
			'unknown unit' => [$at('moreThan', 1, 'weeks'), $doc],
			'missing unit' => [[$k => ['property' => 'createdAt', 'moreThan' => ['value' => 1]]], $doc],
			'zero value' => [$at('moreThan', 0, 'hours'), $doc],
			'negative value' => [$at('moreThan', -2, 'hours'), $doc],
			'too large value' => [$at('moreThan', 10001, 'calendarDays'), $doc],
			'max value' => [$at('lessThan', 10000, 'hours'), $doc],
			'text value' => [$at('moreThan', 'three', 'hours'), $doc],
			'numeric string value' => [$at('moreThan', '2.5', 'hours'), $doc],
			'both comparisons' => [[$k => ['property' => 'createdAt', 'moreThan' => ['value' => 1, 'unit' => 'hours'], 'lessThan' => ['value' => 900, 'unit' => 'hours']]], $doc],
			'both comparisons lessThan first' => [[$k => ['property' => 'createdAt', 'lessThan' => ['value' => 900, 'unit' => 'hours'], 'moreThan' => ['value' => 1, 'unit' => 'hours']]], $doc],
			'both comparisons bad lessThan' => [[$k => ['property' => 'createdAt', 'moreThan' => ['value' => 1, 'unit' => 'hours'], 'lessThan' => 'x']], $doc],
			'missing date' => [$at('moreThan', 1, 'hours'), []],
			'empty date' => [$at('moreThan', 1, 'hours'), ['createdAt' => '']],
			'number date' => [$at('moreThan', 1, 'hours'), ['createdAt' => 5]],
			'unreadable date' => [$at('moreThan', 1, 'hours'), ['createdAt' => 'not a date']],
			'exactly at threshold' => [$at('moreThan', 1, 'hours'), ['createdAt' => '2026-09-14T08:30:00+02:00']],
			'just after threshold' => [$at('moreThan', 1, 'hours'), ['createdAt' => '2026-09-14T08:30:01+02:00']],
		];
		foreach (RelativeTimeCondition::UNITS as $unit) {
			foreach (RelativeTimeCondition::COMPARISONS as $comparison) {
				foreach ([1, 3, 2.5, 40] as $value) {
					$cases["$comparison $value $unit"] = [$at($comparison, $value, $unit), $doc];
				}
			}
		}

		return $cases;
	}//end cases()

	/**
	 * @param array<string, mixed> $document
	 */
	#[DataProvider('cases')]
	public function testMatchesGolden(mixed $node, array $document): void {
		$condition = new RelativeTimeCondition(calculator: new SlaCalculator());
		$now = new DateTimeImmutable(self::NOW);
		$actual = [];
		foreach (['calendar' => WorkingCalendar::fromArray(definition: WorkingCalendarTest::nlNational()), 'none' => null] as $label => $calendar) {
			$actual[$label] = [
				'refusalFor' => $this->outcome(fn () => $condition->refusalFor(node: $node, calendar: $calendar)),
				'compile' => $this->outcome(fn () => $condition->compile(node: $node, moment: new RelativeTimeMoment(now: $now, calendar: $calendar))),
				'holds' => $this->outcome(fn () => $condition->holds(node: $node, check: new RelativeTimeCheck(document: $document, moment: new RelativeTimeMoment(now: $now, calendar: $calendar)))),
			];
		}

		$this->assertGolden($actual);
	}//end testMatchesGolden()

	/**
	 * @return array<string, mixed>
	 */
	private function outcome(callable $call): array {
		try {
			return ['result' => $call()];
		} catch (Throwable $e) {
			return ['threw' => get_class($e), 'message' => $e->getMessage(), 'previous' => ($e->getPrevious() === null ? null : get_class($e->getPrevious()))];
		}
	}//end outcome()

	/**
	 * @param array<string, mixed> $actual
	 */
	private function assertGolden(array $actual): void {
		$actual = json_decode(json_encode($actual), true);
		$name = $this->dataName();
		$golden = [];
		if (is_file(self::FIXTURE) === true) {
			$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertArrayHasKey($name, $golden, 'No golden recording for this case.');
		$this->assertSame($golden[$name], $actual);
	}//end assertGolden()
}//end class
