<?php

/**
 * Behaviour tests for DecisionTableValidator: every structural problem a stored
 * decision table can have.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\Dmn
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Dmn;


use OCA\OpenRegister\Service\Dmn\DecisionTableValidator;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour of the structural validator of a stored decision table.
 */
class DecisionTableValidatorBehaviourTest extends TestCase {

	private static function table(array $override=[]): array {
		return array_merge(
			[
				'hitPolicy' => 'unique',
				'inputs' => [['name' => 'leeftijd', 'type' => 'number']],
				'outputs' => [['name' => 'tarief']],
				'rules' => [['id' => 'r1', 'inputEntries' => ['>= 18'], 'outputEntries' => ['"vol"']]],
			],
			$override
		);
	}//end table()

	public function testAWellFormedTableHasNoProblems(): void {
		$this->assertSame([], (new DecisionTableValidator())->validate(self::table()));
	}

	public function testAnUnknownHitPolicyIsReported(): void {
		$this->assertSame(
			['hit policy "RULE ORDER" is not implemented; the implemented policies are UNIQUE, FIRST, COLLECT, PRIORITY, ANY'],
			(new DecisionTableValidator())->validate(self::table(['hitPolicy' => ' rule order ']))
		);
	}

	public function testMissingColumnsAndRulesAreEachReported(): void {
		$problems = (new DecisionTableValidator())->validate(['inputs' => [], 'outputs' => 'none', 'rules' => []]);

		$this->assertSame(
			[
				'the table declares no inputs; it needs at least one',
				'the table declares no outputs; it needs at least one',
				'the table declares no rules; a table that can never match decides nothing',
			],
			$problems
		);
	}

	public function testBadColumnEntriesAreReportedAndSkipped(): void {
		$problems = (new DecisionTableValidator())->validate(
			self::table(
				[
					'inputs' => ['junk', ['name' => '  '], ['name' => 'a'], ['name' => 'a'], ['name' => 'b']],
					'rules' => [['inputEntries' => ['-', '-'], 'outputEntries' => ['1']]],
				]
			)
		);

		$this->assertSame(
			[
				'inputs entry 0 is not an object',
				'inputs entry 1 has no name',
				'inputs name "a" is declared twice; results are keyed by name, so the columns would collapse',
			],
			$problems
		);
	}

	public function testARuleThatIsNotAnObjectIsReported(): void {
		$this->assertSame(['rule 0 is not an object'], (new DecisionTableValidator())->validate(self::table(['rules' => ['junk']])));
	}

	public function testEntryCountsMustMatchTheColumnsAndAnUnnamedRuleIsNumbered(): void {
		$problems = (new DecisionTableValidator())->validate(
			self::table(['rules' => [self::table()['rules'][0], ['inputEntries' => [], 'outputEntries' => 'x']]])
		);

		$this->assertSame(
			[
				'rule #1 has 0 input entries for 1 declared inputs; a short row would silently wildcard the missing columns',
				'rule #1 has 0 output entries for 1 declared outputs',
			],
			$problems
		);
	}

	public function testANonIntegerPriorityIsReported(): void {
		$rule = self::table()['rules'][0] + ['priority' => '1'];

		$this->assertSame(['rule r1 has a non-integer priority'], (new DecisionTableValidator())->validate(self::table(['rules' => [$rule]])));
	}

	public function testAnIntegerPriorityIsAccepted(): void {
		$rule = self::table()['rules'][0] + ['priority' => 2];

		$this->assertSame([], (new DecisionTableValidator())->validate(self::table(['rules' => [$rule]])));
	}

	public function testACellThatCannotRunAgainstItsColumnTypeIsReported(): void {
		$rule = ['id' => 'r9', 'inputEntries' => ['> abc'], 'outputEntries' => ['1']];

		$this->assertSame(
			['rule r9, column "leeftijd": the cell "> abc" cannot be executed (type_mismatch)'],
			(new DecisionTableValidator())->validate(self::table(['rules' => [$rule]]))
		);
	}
}//end class
