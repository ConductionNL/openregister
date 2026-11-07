<?php

/**
 * Golden test for AdministeredValidations.
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
 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Rules;

use OCA\OpenRegister\Service\Rules\AdministeredValidations;
use OCA\OpenRegister\Service\Rules\ConditionRefusedException;
use OCA\OpenRegister\Service\Rules\NamedConditionEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Golden test for every public method of AdministeredValidations.
 *
 * The evaluator is a stub reading the condition node as an instruction
 * (`violated`, `passes`, `broken`), so every outcome is fixed. The expected
 * values were recorded from the code as it stood before the refactor.
 *
 * @covers \OCA\OpenRegister\Service\Rules\AdministeredValidations
 * @uses   \OCA\OpenRegister\Service\Rules\ConditionRefusedException
 */
class AdministeredValidationsGoldenTest extends TestCase {

	/**
	 * Evaluator calls recorded during a test.
	 *
	 * @var list<string>
	 */
	private array $calls = [];

	/**
	 * The service under test.
	 *
	 * @var AdministeredValidations
	 */
	private AdministeredValidations $validations;

	protected function setUp(): void {
		$evaluator = $this->createMock(NamedConditionEvaluator::class);
		$evaluator->method('holds')->willReturnCallback(
			function (mixed $node, array $document, array $library): bool {
				$this->calls[] = json_encode([$node, $document, $library]);
				if ($node === 'broken') {
					throw new ConditionRefusedException('lib.broken', 'it references itself');
				}

				return $node === 'violated';
			}
		);
		$this->validations = new AdministeredValidations($evaluator);
	}//end setUp()

	public function testDeclarationsFrom(): void {
		$this->assertSame([], $this->validations->declarationsFrom(null));
		$this->assertSame(
			['a' => ['x' => 1], '5' => []],
			$this->validations->declarationsFrom(['a' => ['x' => 1], '' => ['y' => 1], 'b' => 'scalar', 5 => []])
		);
	}//end testDeclarationsFrom()

	public function testRefusalFor(): void {
		$ok = ['condition' => 'c', 'severity' => 'warn', 'message' => 'Te hoog', 'properties' => ['bedrag']];
		$cases = [
			'none' => [null, [], null],
			'valid' => [['v' => $ok], ['bedrag'], null],
			'no condition' => [['v' => ['severity' => 'warn']], [], 'validation "v" declares no condition'],
			'null condition is a condition' => [['v' => ['condition' => null] + $ok], [], null],
			'missing severity' => [
				['v' => ['condition' => 'c']],
				[],
				'validation "v" declares severity "": use one of refuse or warn',
			],
			'odd severity' => [
				['v' => ['severity' => 'Refuse'] + $ok],
				[],
				'validation "v" declares severity "Refuse": use one of refuse or warn',
			],
			'no message' => [
				['v' => ['message' => '  '] + $ok],
				[],
				'validation "v" carries no message; a check whose sentence nobody wrote is not shipped, '
				. 'because a generic one is how every validation ends up saying the same thing',
			],
			'empty message map' => [
				['v' => ['message' => ['nl' => '', 'en' => 3]] + $ok],
				[],
				'validation "v" carries no message; a check whose sentence nobody wrote is not shipped, '
				. 'because a generic one is how every validation ends up saying the same thing',
			],
			'properties not a list' => [
				['v' => ['properties' => 'bedrag'] + $ok],
				['bedrag'],
				'validation "v" must name its properties as a list',
			],
			'unknown property' => [
				['v' => ['properties' => ['bedrag', 7, 'mandaat']] + $ok],
				['bedrag', 'mandaat'],
				'validation "v" points at "7", which this schema does not declare',
			],
			'unknown property ignored without schema list' => [['v' => ['properties' => ['zzz']] + $ok], [], null],
			'first invalid wins' => [
				['a' => $ok, 3 => ['severity' => 'warn'], 'c' => ['condition' => 'c']],
				['bedrag'],
				'validation "3" declares no condition',
			],
		];
		foreach ($cases as $name => [$annotation, $properties, $expected]) {
			$this->assertSame($expected, $this->validations->refusalFor($annotation, $properties), $name);
		}

		$this->assertNull($this->validations->refusalFor(['v' => $ok]));
	}//end testRefusalFor()

	public function testMessageIn(): void {
		$declaration = ['message' => ['nl' => 'Te hoog', 'en' => 'Too high', 'de' => 'Zu hoch', 'fr' => '']];
		$cases = [
			'en' => 'Too high',
			'en_GB' => 'Too high',
			'EN-us' => 'Too high',
			'de' => 'Zu hoch',
			'fr' => 'Te hoog',
			'' => 'Te hoog',
			'pt_BR' => 'Te hoog',
		];
		foreach ($cases as $language => $expected) {
			$this->assertSame($expected, $this->validations->messageIn($declaration, (string)$language), (string)$language);
		}

		$this->assertSame('Seul', $this->validations->messageIn(['message' => ['fr' => 'Seul', 'es' => 'Solo']], 'en'));
		$this->assertSame('Kaal', $this->validations->messageIn(['message' => 'Kaal'], 'en'));
		$this->assertSame('', $this->validations->messageIn(['message' => ' '], 'en'));
		$this->assertSame('', $this->validations->messageIn([], 'nl'));
		$this->assertSame('Drie', $this->validations->messageIn(['message' => [3 => 'Drie']], 'en'));
	}//end testMessageIn()

	public function testEvaluate(): void {
		$annotation = [
			'tooHigh' => [
				'condition' => 'violated',
				'severity' => 'refuse',
				'message' => ['nl' => 'Te hoog', 'en' => 'Too high'],
				'properties' => ['bedrag', 5],
			],
			'fine' => ['condition' => 'passes', 'severity' => 'refuse', 'message' => 'Nooit'],
			'nudge' => ['condition' => 'violated', 'severity' => 'warn', 'message' => 'Let op', 'properties' => 'x'],
			'broken' => ['condition' => 'broken', 'severity' => 'warn', 'message' => 'Kapot', 'properties' => ['mandaat']],
			'defaulted' => ['condition' => 'violated'],
			'odd' => ['condition' => 'violated', 'severity' => 'loud', 'message' => 'Hard'],
			'noCondition' => ['severity' => 'warn', 'message' => 'Leeg'],
			'skip' => 'not an array',
		];
		$expected = [
			'refusals' => [
				[
					'validation' => 'tooHigh',
					'severity' => 'refuse',
					'properties' => ['bedrag', '5'],
					'message' => 'Too high',
					'unevaluable' => false,
				],
				[
					'validation' => 'broken',
					'severity' => 'refuse',
					'properties' => ['mandaat'],
					'message' => 'This check could not be evaluated, so the save is refused: it references itself',
					'unevaluable' => true,
				],
				[
					'validation' => 'defaulted',
					'severity' => 'refuse',
					'properties' => [],
					'message' => '',
					'unevaluable' => false,
				],
				[
					'validation' => 'odd',
					'severity' => 'loud',
					'properties' => [],
					'message' => 'Hard',
					'unevaluable' => false,
				],
			],
			'warnings' => [
				[
					'validation' => 'nudge',
					'severity' => 'warn',
					'properties' => [],
					'message' => 'Let op',
					'unevaluable' => false,
				],
			],
		];
		$this->assertSame(
			$expected,
			$this->validations->evaluate($annotation, ['bedrag' => 60000], ['lib' => ['expression' => true]], 'en_GB')
		);
		$this->assertCount(7, $this->calls);
		$this->assertSame('[null,{"bedrag":60000},{"lib":{"expression":true}}]', $this->calls[6]);

		$this->calls = [];
		$this->assertSame(['refusals' => [], 'warnings' => []], $this->validations->evaluate(null, []));
		$this->assertSame([], $this->calls);

		$nudge    = ['condition' => 'violated', 'severity' => 'warn', 'message' => ['nl' => 'Standaard', 'en' => 'Default']];
		$defaults = $this->validations->evaluate(['n' => $nudge], ['a' => 1]);
		$this->assertSame('Standaard', $defaults['warnings'][0]['message']);
		$this->assertSame('["violated",{"a":1},[]]', $this->calls[0]);
	}//end testEvaluate()
}//end class
