<?php

/**
 * Golden characterization test for ScheduledFilterEvaluator.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Notification;

use DateTimeImmutable;
use OCA\OpenRegister\Notifications\Service\Notification\ScheduledFilterEvaluator;
use OCA\OpenRegister\Notifications\Service\Notification\ScheduledFilterTarget;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Evaluates fixed filters against fixed objects at a fixed moment and compares
 * every answer and every log record with
 * `fixtures/scheduled-filter-evaluator-golden.json`. All dates are absolute
 * with an explicit offset; relative operands are measured from the fixed
 * moment, never from today. Set `OR_GOLDEN_WRITE=1` to regenerate the fixture.
 *
 * @covers \OCA\OpenRegister\Notifications\Service\Notification\ScheduledFilterEvaluator
 */
final class ScheduledFilterEvaluatorGoldenTest extends TestCase {

	/**
	 * Path of the golden file.
	 *
	 * @return string
	 */
	private static function goldenPath(): string {
		return __DIR__.'/fixtures/scheduled-filter-evaluator-golden.json';
	}//end goldenPath()

	/**
	 * Objects every filter is evaluated against.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function objects(): array {
		return [
			[],
			['status' => 'open', 'count' => 1, 'tags' => ['a', 'b'], 'due' => '2026-06-01T00:00:00+00:00', 'n' => null],
			['status' => 'paid', 'count' => '1', 'tags' => ['c'], 'due' => '2026-06-20T00:00:00+00:00'],
			['status' => null, 'tags' => 'a', 'due' => '2026-06-15T12:00:00+00:00'],
			['due' => 'not a date', 'tags' => []],
			['due' => 12345],
			['due' => ''],
			['due' => '2026-06-17T12:00:00+00:00'],
			['due' => '2026-06-08T12:00:00+00:00'],
		];
	}//end objects()

	/**
	 * The filters, by name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function filters(): array {
		return [
			'empty' => [],
			'scalar' => ['status' => 'open'],
			'scalar-int' => ['count' => 1],
			'bare-list' => ['tags' => ['b', 'c']],
			'equals' => ['status' => ['operator' => 'equals', 'value' => 'paid']],
			'not-equals' => ['status' => ['operator' => 'notEquals', 'value' => 'open']],
			'not-equals-null' => ['status' => ['operator' => 'notEquals', 'value' => null]],
			'in' => ['status' => ['operator' => 'in', 'values' => ['open', 'paid']]],
			'in-null' => ['status' => ['operator' => 'in', 'values' => [null, 'x']]],
			'not-in' => ['status' => ['operator' => 'notIn', 'values' => ['paid']]],
			'not-in-null' => ['status' => ['operator' => 'notIn', 'values' => [null]]],
			'not-in-tags' => ['tags' => ['operator' => 'notIn', 'values' => ['a']]],
			'before-now' => ['due' => ['operator' => 'before', 'value' => 'now']],
			'after-now' => ['due' => ['operator' => 'after', 'value' => 'now']],
			'before-absolute' => ['due' => ['operator' => 'before', 'value' => '2026-06-10']],
			'after-absolute' => ['due' => ['operator' => 'after', 'value' => '2026-06-10T00:00:00+00:00']],
			'before-past-duration' => ['due' => ['operator' => 'before', 'value' => '-P7D']],
			'after-future-duration' => ['due' => ['operator' => 'after', 'value' => 'P3D']],
			'within-next' => ['due' => ['operator' => 'withinNext', 'value' => 'P7D']],
			'older-than' => ['due' => ['operator' => 'olderThan', 'value' => 'P7D']],
			'within-next-hours' => ['due' => ['operator' => 'withinNext', 'value' => 'PT36H']],
			'all' => ['all' => [['field' => 'status', 'operator' => 'equals', 'value' => 'open'], ['field' => 'due', 'operator' => 'before', 'value' => 'now']]],
			'any' => ['any' => [['field' => 'status', 'operator' => 'equals', 'value' => 'paid'], ['field' => 'due', 'operator' => 'withinNext', 'value' => 'P7D']]],
			'empty-all' => ['all' => []],
			'empty-any' => ['any' => []],
			'nested' => ['any' => [['all' => [['field' => 'count', 'operator' => 'equals', 'value' => 1], ['field' => 'tags', 'operator' => 'in', 'values' => ['a']]]], ['field' => 'status', 'operator' => 'notIn', 'values' => ['open', 'paid']]]],
			'two-entries' => ['status' => 'open', 'due' => ['operator' => 'after', 'value' => '-P30D']],
			'unexecutable' => ['isEnabled' => ['op' => 'equals', 'value' => true]],
			'unknown-operator' => ['due' => ['operator' => 'around', 'value' => 'now']],
		];
	}//end filters()

	/**
	 * Case names for the data provider.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function cases(): array {
		$cases = [];
		foreach (array_keys(self::filters()) as $name) {
			$cases[$name] = [$name];
		}

		return $cases;
	}//end cases()

	/**
	 * Each filter answers and logs as recorded, object by object.
	 *
	 * @param string $name The filter name.
	 *
	 * @return void
	 *
	 * @dataProvider cases
	 */
	public function testFilterMatchesGolden(string $name): void {
		$logger = new class extends AbstractLogger {
			/**
			 * Recorded log calls.
			 *
			 * @var array<int, array<int, mixed>>
			 */
			public array $records = [];

			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->records[] = [$level, (string)$message, $context];
			}
		};
		$evaluator = new ScheduledFilterEvaluator($logger);
		$now = new DateTimeImmutable('2026-06-10T12:00:00+00:00');

		$actual = [];
		foreach (self::objects() as $index => $object) {
			$logger->records = [];
			$actual[] = [$index, $evaluator->matchesTarget(filter: self::filters()[$name], target: new ScheduledFilterTarget(objectData: $object, now: $now)), $logger->records];
		}

		if (getenv('OR_GOLDEN_WRITE') === '1') {
			$golden = [];
			if (is_file(self::goldenPath()) === true) {
				$golden = json_decode((string)file_get_contents(self::goldenPath()), true);
			}

			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::goldenPath(), json_encode($golden, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
			$this->markTestSkipped('golden written');
		}

		$golden = json_decode((string)file_get_contents(self::goldenPath()), true);
		$this->assertArrayHasKey($name, $golden);
		$this->assertSame($golden[$name], $actual);
	}//end testFilterMatchesGolden()
}//end class
