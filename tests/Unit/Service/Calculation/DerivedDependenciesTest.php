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
	private CalculationEvaluator $eval;
	private CalculationAnnotationValidator $validator;

	protected function setUp(): void {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);
		$this->eval = new CalculationEvaluator(new PlaceholderResolver($userSession));
		$this->validator = new CalculationAnnotationValidator();
	}

	public function testNestedPropReferencesAreAllFound(): void {
		$expression = [
			'if' => [
				['gt' => [['prop' => 'bedrag'], 1000]],
				['concat' => [['prop' => 'naam'], ' (groot)']],
				['prop' => 'naam'],
			],
		];

		$this->assertSame(['bedrag', 'naam'], $this->eval->referencedProperties($expression));
	}

	public function testDictShapedOperatorsAreWalkedToo(): void {
		$expression = [
			'dateAdd' => [
				'date' => ['prop' => 'ontvangstdatum'],
				'amount' => ['prop' => 'termijnWeken'],
				'unit' => 'weeks',
			],
		];

		$this->assertSame(['ontvangstdatum', 'termijnWeken'], $this->eval->referencedProperties($expression));
	}

	public function testSystemAndReferencePrefixesAreReturnedAsWritten(): void {
		$expression = ['diffDays' => [['prop' => '@self.created'], ['prop' => '@ref.zaak.startdatum']]];

		$this->assertSame(
			['@self.created', '@ref.zaak.startdatum'],
			$this->eval->referencedProperties($expression)
		);
	}

	public function testABareScalarReadsNothing(): void {
		$this->assertSame([], $this->eval->referencedProperties('$now'));
		$this->assertSame([], $this->eval->referencedProperties(null));
	}

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
		$this->assertContains('calculation-cycle', $codes);
	}

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
		$this->assertContains('calculation-dependson-ignored', $codes);
	}
}
