<?php

/**
 * Golden test for TaskPriority.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Task
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/flow-tasks/spec.md#requirement-priority-is-normalised-to-one-scale-on-the-way-in
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Task;

use OCA\OpenRegister\Exception\TaskValidationException;
use OCA\OpenRegister\Service\Task\TaskPriority;
use PHPUnit\Framework\TestCase;

/**
 * Golden test for TaskPriority::normalise().
 *
 * Every input in the table is normalised and its result, or the refusal
 * message, is compared with the value recorded from the code as it stood
 * before the refactor.
 *
 * @covers \OCA\OpenRegister\Service\Task\TaskPriority
 * @uses   \OCA\OpenRegister\Exception\TaskValidationException
 */
class TaskPriorityGoldenTest extends TestCase {

	/**
	 * Input → normalised value, or `!` followed by the refusal message.
	 *
	 * @return array<int, array{0: mixed, 1: string}>
	 */
	private static function cases(): array {
		$scale = ' is on no known scale (low|normal|high|urgent, low|medium|high|critical, or iCal 0-9) and is refused, not coerced.';
		$range = ' is outside the iCal 0-9 range and is refused, not coerced.';
		return [
			['low', 'low'],
			['normal', 'normal'],
			['high', 'high'],
			['urgent', 'urgent'],
			['medium', 'normal'],
			['critical', 'urgent'],
			['  HIGH ', 'high'],
			['Critical', 'urgent'],
			[0, 'normal'],
			[1, 'urgent'],
			[2, 'high'],
			[3, 'high'],
			[4, 'high'],
			[5, 'normal'],
			[6, 'low'],
			[7, 'low'],
			[8, 'low'],
			[9, 'low'],
			['1', 'urgent'],
			[' 7 ', 'low'],
			['05', 'normal'],
			[10, "!Priority '10'" . $range],
			[-1, "!Priority '-1'" . $range],
			['12', "!Priority '12'" . $range],
			['-1', "!Priority '-1'" . $scale],
			['normaal', "!Priority 'normaal'" . $scale],
			['', "!Priority ''" . $scale],
			[1.5, "!Priority '1.5'" . $scale],
			[true, "!Priority '1'" . $scale],
			[false, "!Priority ''" . $scale],
			[null, "!Priority 'NULL'" . $scale],
			[['high'], "!Priority 'array'" . $scale],
		];
	}//end cases()

	public function testEveryInputMatchesTheRecordedResult(): void {
		foreach (self::cases() as $index => [$input, $expected]) {
			$this->assertSame($expected, self::outcome(value: $input), 'case ' . $index);
		}
	}//end testEveryInputMatchesTheRecordedResult()

	private static function outcome(mixed $value): string {
		try {
			return TaskPriority::normalise(value: $value);
		} catch (TaskValidationException $exception) {
			return '!' . $exception->getMessage();
		}
	}//end outcome()
}//end class
