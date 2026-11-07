<?php

/**
 * Golden test for NamedConditionEvaluator.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rules
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Rules;

use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCA\OpenRegister\Service\Rules\ConditionDialect;
use OCA\OpenRegister\Service\Rules\ConditionRefusedException;
use OCA\OpenRegister\Service\Rules\NamedConditionEvaluator;
use OCA\OpenRegister\Service\Rules\NamedConditionLibrary;
use PHPUnit\Framework\TestCase;

/**
 * Golden test for NamedConditionEvaluator::holds().
 *
 * A fixed library and document over the real JSONLogic dialect. Each node's
 * verdict, or its refusal's condition name and reason, was recorded from the
 * code as it stood before the refactor.
 *
 * @covers \OCA\OpenRegister\Service\Rules\NamedConditionEvaluator
 * @uses   \OCA\OpenRegister\Service\Rules\NamedConditionLibrary
 * @uses   \OCA\OpenRegister\Service\Rules\ConditionDialect
 * @uses   \OCA\OpenRegister\Service\Rules\ConditionRefusedException
 */
class NamedConditionEvaluatorGoldenTest extends TestCase {

	private static function ref(string $name): array {
		return [NamedConditionLibrary::REF => $name];
	}//end ref()

	private static function eq(string $field, string $value): array {
		return ['==' => [['var' => $field], $value]];
	}//end eq()

	private static function library(): array {
		return [
			'hoog' => ['expression' => self::eq('prioriteit', 'hoog')],
			'laag' => ['expression' => self::eq('prioriteit', 'laag')],
			'open' => ['expression' => self::eq('status', 'open')],
			'nietHoog' => ['expression' => ['not' => self::ref('hoog')]],
			'hoogEnOpen' => ['expression' => ['and' => [self::ref('hoog'), self::ref('open')]]],
			'lus' => ['expression' => self::ref('lus')],
			'leeg' => ['description' => 'no expression'],
			'kaal' => 'just a string',
			'waar' => ['expression' => true],
			'nul' => ['expression' => null],
			'c0' => ['expression' => self::ref('c1')],
			'c1' => ['expression' => self::ref('c2')],
			'c2' => ['expression' => self::ref('c3')],
			'c3' => ['expression' => self::ref('c4')],
			'c4' => ['expression' => self::eq('status', 'dicht')],
			'pre' => ['expression' => self::ref('c0')],
		];
	}//end library()

	public function testHoldsMatchesTheRecordedVerdicts(): void {
		$evaluator = new NamedConditionEvaluator(
			new ConditionDialect($this->createMock(CalculationEvaluator::class)),
			new NamedConditionLibrary()
		);
		$document = ['prioriteit' => 'hoog', 'status' => 'dicht'];
		$cases = [
			'plain true' => [true, 'true'],
			'plain empty' => [[], 'false'],
			'plain string' => ['x', 'true'],
			'plain jsonlogic' => [self::eq('prioriteit', 'hoog'), 'true'],
			'plain jsonlogic false' => [['and' => [self::eq('prioriteit', 'hoog'), self::eq('status', 'open')]], 'false'],
			'ref' => [self::ref('hoog'), 'true'],
			'ref false' => [self::ref('open'), 'false'],
			'ref to not' => [self::ref('nietHoog'), 'false'],
			'ref to and' => [self::ref('hoogEnOpen'), 'false'],
			'ref beside other keys' => [[NamedConditionLibrary::REF => 'hoog', 'or' => [true]], 'true'],
			'ref to true' => [self::ref('waar'), 'true'],
			'ref to null' => [self::ref('nul'), 'false'],
			'and of refs' => [['and' => [self::ref('hoog'), ['not' => self::ref('open')]]], 'true'],
			'and short-circuits' => [['and' => [self::ref('open'), self::ref('ontbreekt')]], 'false'],
			'or short-circuits' => [['or' => [self::ref('hoog'), self::ref('ontbreekt')]], 'true'],
			'or all false' => [['or' => [self::ref('laag'), self::ref('open')]], 'false'],
			'and single map child' => [['and' => self::ref('hoog')], 'true'],
			'or single map child' => [['or' => self::ref('open')], 'false'],
			'not list' => [['not' => [self::ref('open')]], 'true'],
			'bang' => [['!' => self::ref('hoog')], 'false'],
			'not empty list' => [['not' => [['and' => [self::ref('hoog')]]]], 'false'],
			'mixed plain and ref' => [['and' => [self::eq('status', 'dicht'), self::ref('hoog')]], 'true'],
			'five references deep is fine' => [self::ref('c0'), 'true'],
			'branches do not add depth' => [['not' => ['not' => ['and' => [self::ref('c0')]]]], 'true'],
			'six references deep is refused' => [self::ref('pre'), '!c4: composition is deeper than the administered depth of 5'],
			'loop' => [self::ref('lus'), '!lus: composition is deeper than the administered depth of 5'],
			'undeclared' => [self::ref('ontbreekt'), '!ontbreekt: it is not declared in this schema\'s condition library'],
			'no expression' => [self::ref('leeg'), '!leeg: it is declared with no expression behind it'],
			'not an object' => [self::ref('kaal'), '!kaal: it is declared with no expression behind it'],
			'ref inside if' => [
				['if' => [self::ref('hoog'), true, false]],
				'!hoog: a named condition sits inside "if", which this evaluator cannot compose',
			],
			'two refs inside ==' => [
				['==' => [true, true], 'and' => [self::ref('hoog'), self::ref('laag')]],
				'!hoog, laag: a named condition sits inside "==", which this evaluator cannot compose',
			],
			'and with a scalar' => [
				['and' => 'nope', 'or' => [self::ref('hoog')]],
				'!hoog: a named condition sits inside "and", which this evaluator cannot compose',
			],
		];
		foreach ($cases as $name => [$node, $expected]) {
			$this->assertSame($expected, self::verdict($evaluator, $node, $document), $name);
		}

		$this->assertSame('!c4: composition is deeper than the administered depth of 5', self::verdict($evaluator, self::ref('c0'), $document, 1));
		$this->assertSame('!c0: composition is deeper than the administered depth of 5', self::verdict($evaluator, self::ref('c0'), $document, 5));
		$this->assertSame('true', self::verdict($evaluator, self::ref('c4'), $document, 4));
	}//end testHoldsMatchesTheRecordedVerdicts()

	private static function verdict(NamedConditionEvaluator $evaluator, mixed $node, array $document, int $depth = 0): string {
		try {
			return var_export($evaluator->holds($node, $document, self::library(), $depth), true);
		} catch (ConditionRefusedException $refused) {
			return '!' . $refused->getConditionName() . ': ' . $refused->getWhy();
		}
	}//end verdict()
}//end class
