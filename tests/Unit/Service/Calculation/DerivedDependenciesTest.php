<?php

declare(strict_types=1);

namespace Unit\Service\Calculation;

use OCA\OpenRegister\Service\Calculation\CalculationAnnotationValidator;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCA\OpenRegister\Service\Search\PlaceholderResolver;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Dependencies are read out of the expression, not declared beside it.
 *
 * A `dependsOn` list is a second source of truth and it fails silently: a
 * dependency the author forgot is a value that never refreshes. These tests
 * pin that the list comes from the AST and that a declared one is both
 * unnecessary and reported as unread.
 */
class DerivedDependenciesTest extends TestCase {
	/**
	 * @var CalculationEvaluator The pure evaluator, which derives the list.
	 */
	private CalculationEvaluator $eval;
	/**
	 * @var CalculationAnnotationValidator The validator, which runs the cycle check on it.
	 */
	private CalculationAnnotationValidator $validator;

	/**
	 * Wire the collaborators this suite needs.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$userSession = $this->createMock(originalClassName: IUserSession::class);
		$userSession->method('getUser')->willReturn(null);
		$this->eval = new CalculationEvaluator(new PlaceholderResolver($userSession));
		$this->validator = new CalculationAnnotationValidator();
	}

	/**
	 * Nested prop references are all found.
	 *
	 * @return void
	 */
	public function testNestedPropReferencesAreAllFound(): void {
		$expression = [
			'if' => [
				['gt' => [['prop' => 'bedrag'], 1000]],
				['concat' => [['prop' => 'naam'], ' (groot)']],
				['prop' => 'naam'],
			],
		];

		$this->assertSame(expected: ['bedrag', 'naam'], actual: $this->eval->referencedProperties($expression));
	}

	/**
	 * Dict shaped operators are walked too.
	 *
	 * @return void
	 */
	public function testDictShapedOperatorsAreWalkedToo(): void {
		$expression = [
			'dateAdd' => [
				'date' => ['prop' => 'ontvangstdatum'],
				'amount' => ['prop' => 'termijnWeken'],
				'unit' => 'weeks',
			],
		];

		$this->assertSame(expected: ['ontvangstdatum', 'termijnWeken'], actual: $this->eval->referencedProperties($expression));
	}

	/**
	 * System and reference prefixes are returned as written.
	 *
	 * @return void
	 */
	public function testSystemAndReferencePrefixesAreReturnedAsWritten(): void {
		$expression = ['diffDays' => [['prop' => '@self.created'], ['prop' => '@ref.zaak.startdatum']]];

		$this->assertSame(expected: ['@self.created', '@ref.zaak.startdatum'], actual: $this->eval->referencedProperties($expression));
	}

	/**
	 * A bare scalar reads nothing.
	 *
	 * @return void
	 */
	public function testABareScalarReadsNothing(): void {
		$this->assertSame(expected: [], actual: $this->eval->referencedProperties('$now'));
		$this->assertSame(expected: [], actual: $this->eval->referencedProperties(null));
	}

	/**
	 * A cycle is caught with no declared dependency lists.
	 *
	 * @return void
	 */
	public function testACycleIsCaughtWithNoDeclaredDependencyLists(): void {
		$errors = $this->validator->validate(
			[
				'properties' => [],
				'x-openregister-calculations' => [
					'a' => ['type' => 'integer', 'expression' => ['+' => [['prop' => 'b'], 1]]],
					'b' => ['type' => 'integer', 'expression' => ['+' => [['prop' => 'a'], 1]]],
				],
			]
		);

		$codes = array_column($errors, 'code');
		$this->assertContains(needle: 'calculation-cycle', haystack: $codes);
	}

	/**
	 * A declared dependency list is reported as unread.
	 *
	 * @return void
	 */
	public function testADeclaredDependencyListIsReportedAsUnread(): void {
		$errors = $this->validator->validate(
			[
				'properties' => ['bedrag' => ['type' => 'number']],
				'x-openregister-calculations' => [
					'totaal' => [
						'type' => 'number',
						'expression' => ['+' => [['prop' => 'bedrag'], 1]],
						'dependsOn' => ['somethingElse'],
					],
				],
			]
		);

		$codes = array_column($errors, 'code');
		$this->assertContains(needle: 'calculation-dependson-ignored', haystack: $codes);
	}
}
