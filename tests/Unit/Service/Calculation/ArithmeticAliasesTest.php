<?php

/**
 * The word forms of the arithmetic operators validate and evaluate as their symbols.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Calculation
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://OpenRegister.app
 *
 * @spec openspec/changes/aggregation-dialect-repair/tasks.md
 */

declare(strict_types=1);

namespace Unit\Service\Calculation;

use OCA\OpenRegister\Search\Service\Search\PlaceholderResolver;
use OCA\OpenRegister\Service\Calculation\CalculationAnnotationValidator;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCA\OpenRegister\Service\Calculation\EvaluationException;
use OCA\OpenRegister\Service\Calculation\OperatorCatalogue;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * `add`, `sub`, `mul` and `div` are the same operators as `+`, `-`, `*` and `/`.
 *
 * @covers \OCA\OpenRegister\Service\Calculation\CalculationEvaluator
 * @covers \OCA\OpenRegister\Service\Calculation\CalculationAnnotationValidator
 * @uses   \OCA\OpenRegister\Service\Calculation\OperatorCatalogue
 * @uses   \OCA\OpenRegister\Search\Service\Search\PlaceholderResolver
 */
class ArithmeticAliasesTest extends TestCase {

	/**
	 * Each word with its symbol and operands.
	 *
	 * @return array<string, array{0: string, 1: string, 2: array<int, mixed>}>
	 */
	public static function aliases(): array {
		return [
			'add' => ['add', '+', [['prop' => 'price'], 3, 2.5]],
			'sub' => ['sub', '-', [['prop' => 'price'], 4, 1]],
			'sub, one operand negates' => ['sub', '-', [['prop' => 'price']]],
			'mul' => ['mul', '*', [['prop' => 'price'], ['prop' => 'quantity']]],
			'div' => ['div', '/', [['prop' => 'price'], 4]],
		];
	}//end aliases()

	/**
	 * The evaluator.
	 *
	 * @return CalculationEvaluator
	 */
	private function evaluator(): CalculationEvaluator {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);

		return new CalculationEvaluator(new PlaceholderResolver($session));
	}//end evaluator()

	/**
	 * A word evaluates to exactly what its symbol evaluates to.
	 *
	 * @param string            $word     The word form.
	 * @param string            $symbol   The symbol form.
	 * @param array<int, mixed> $operands The operands.
	 *
	 * @return void
	 *
	 * @dataProvider aliases
	 */
	public function testAWordEvaluatesAsItsSymbol(string $word, string $symbol, array $operands): void {
		$object = ['price' => 10, 'quantity' => 3];
		$evaluator = $this->evaluator();

		$expected = $evaluator->evaluate($object, [$symbol => $operands]);

		$this->assertSame($expected, $evaluator->evaluate($object, [$word => $operands]));
	}//end testAWordEvaluatesAsItsSymbol()

	/**
	 * The values themselves, so a broken symbol cannot hide behind an equally broken word.
	 *
	 * @return void
	 */
	public function testTheWordsComputeTheExpectedValues(): void {
		$evaluator = $this->evaluator();
		$object = ['price' => 10, 'quantity' => 3];

		$this->assertSame(15.5, $evaluator->evaluate($object, ['add' => [['prop' => 'price'], 3, 2.5]]));
		$this->assertSame(5, $evaluator->evaluate($object, ['sub' => [['prop' => 'price'], 4, 1]]));
		$this->assertSame(-10, $evaluator->evaluate($object, ['sub' => [['prop' => 'price']]]));
		$this->assertSame(30, $evaluator->evaluate($object, ['mul' => [['prop' => 'price'], ['prop' => 'quantity']]]));
		$this->assertEquals(2.5, $evaluator->evaluate($object, ['div' => [['prop' => 'price'], 4]]));
	}//end testTheWordsComputeTheExpectedValues()

	/**
	 * `div` refuses a zero divisor as `/` does.
	 *
	 * @return void
	 */
	public function testDivRefusesAZeroDivisorAsTheSymbolDoes(): void {
		$evaluator = $this->evaluator();
		$messages = [];
		foreach (['/', 'div'] as $op) {
			try {
				$evaluator->evaluate(['price' => 10], [$op => [['prop' => 'price'], 0]]);
				$messages[] = 'no exception';
			} catch (EvaluationException $e) {
				$messages[] = $e->getMessage();
			}
		}

		$this->assertNotSame('no exception', $messages[0]);
		$this->assertSame($messages[0], $messages[1]);
	}//end testDivRefusesAZeroDivisorAsTheSymbolDoes()

	/**
	 * The validator accepts the words; an unknown word is still refused.
	 *
	 * @return void
	 */
	public function testTheValidatorAcceptsTheWords(): void {
		$validator = new CalculationAnnotationValidator();
		$schema = static fn (array $expression): array => [
			'properties' => ['price' => ['type' => 'number'], 'quantity' => ['type' => 'number']],
			'x-openregister-calculations' => ['total' => ['type' => 'number', 'expression' => $expression]],
		];

		foreach (['add', 'sub', 'mul', 'div'] as $word) {
			$this->assertSame([], $validator->validate($schema([$word => [['prop' => 'price'], ['prop' => 'quantity']]])), $word);
		}

		$errors = $validator->validate($schema(['times' => [['prop' => 'price'], 2]]));
		$this->assertSame(['calculation-unknown-op'], array_column($errors, 'code'));
	}//end testTheValidatorAcceptsTheWords()

	/**
	 * The catalogue lists the words beside the symbols, as arithmetic.
	 *
	 * @return void
	 */
	public function testTheCatalogueListsTheWords(): void {
		$catalogue = new OperatorCatalogue();

		foreach (CalculationEvaluator::ARITHMETIC_ALIASES as $word => $symbol) {
			$this->assertTrue($catalogue->has($word), $word);
			$this->assertSame(
				CalculationEvaluator::OPERATORS[$symbol]['arity'],
				CalculationEvaluator::OPERATORS[$word]['arity'],
				$word
			);
			$this->assertSame('arithmetic', CalculationEvaluator::OPERATORS[$word]['category']);
		}
	}//end testTheCatalogueListsTheWords()
}//end class
