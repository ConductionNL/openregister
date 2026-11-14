<?php

/**
 * Characterisation tests for which retention rule applies to a row.
 *
 * Pins `RetentionEvaluator::firstMatchingRule()` directly (it is private; the
 * public `evaluate()` signature is being changed elsewhere, so this test does
 * not go through it): rules that are not a list, unusable rules skipped,
 * a malformed condition skipped, the first matching rule winning with its
 * index, and null when nothing matches.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Archival
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Archival;

use OCA\OpenRegister\Service\Archival\RetentionEvaluator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * Golden tests for firstMatchingRule().
 */
class RetentionEvaluatorRuleMatchGoldenTest extends TestCase {

	/**
	 * Run the private rule match.
	 *
	 * @param mixed                $rules The rules.
	 * @param array<string, mixed> $row   The row.
	 *
	 * @return array{index: int, retention: string}|null
	 */
	private function match(mixed $rules, array $row): ?array {
		$method = new ReflectionMethod(RetentionEvaluator::class, 'firstMatchingRule');

		return $method->invoke(new RetentionEvaluator(logger: new NullLogger()), $rules, $row);
	}//end match()

	/**
	 * Every rule shape is skipped or matched as before.
	 *
	 * @return void
	 */
	public function testFirstMatchingRuleForEveryShape(): void {
		$row = ['statusCode' => 500];

		$this->assertNull($this->match(null, $row));
		$this->assertNull($this->match('statusCode >= 400', $row));
		$this->assertNull($this->match([], $row));
		$this->assertSame(
			['index' => 4, 'retention' => 'P30D'],
			$this->match(
				[
					'not-a-rule',
					['condition' => 'statusCode >= 400'],
					['retention' => 'P1D'],
					['condition' => 'statusCode < 400', 'retention' => 'PT1H'],
					['condition' => 'statusCode >= 400', 'retention' => 'P30D'],
					['condition' => 'statusCode >= 500', 'retention' => 'P90D'],
				],
				$row
			)
		);
		$this->assertSame(
			['index' => 2, 'retention' => 'P7D'],
			$this->match(['a' => ['condition' => 'statusCode <', 'retention' => 'P1D'], 2 => ['condition' => 'statusCode > 1', 'retention' => 'P7D']], $row)
		);
		$this->assertNull($this->match([['condition' => 'statusCode < 100', 'retention' => 'P1D']], $row));
	}//end testFirstMatchingRuleForEveryShape()
}//end class
