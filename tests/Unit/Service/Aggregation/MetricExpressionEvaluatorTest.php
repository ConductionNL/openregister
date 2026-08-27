<?php

/**
 * Unit tests for MetricExpressionEvaluator.
 *
 * The evaluator is pure, so these exercise the real parser rather than a
 * fixture that agrees with it.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Aggregation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Aggregation;

use OCA\OpenRegister\Service\Aggregation\MetricExpressionEvaluator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * No `covers` metadata, deliberately — `beStrictAboutCoverageMetadata="true"`
 * discards the coverage of any test that touches a collaborator it did not name.
 */
class MetricExpressionEvaluatorTest extends TestCase {

	private MetricExpressionEvaluator $evaluator;

	protected function setUp(): void {
		parent::setUp();
		$this->evaluator = new MetricExpressionEvaluator();
	}

	/**
	 * @test
	 * The shapes the shillinq registers actually declare.
	 */
	public function testEvaluatesTheDeclaredShapes(): void {
		$scope = [
			'totalVATPaid' => 300.0,
			'totalVATCollected' => 125.0,
			'openingBalanceRj' => 1000,
			'adjustmentTotal' => -250.5,
		];

		// vatBalance = totalVATPaid - totalVATCollected
		$this->assertEqualsWithDelta(
			175.0,
			$this->evaluator->evaluate('totalVATPaid - totalVATCollected', $scope),
			0.001
		);

		// openingBalanceRj + adjustmentTotal — an int alias and a negative one.
		$this->assertEqualsWithDelta(
			749.5,
			$this->evaluator->evaluate('openingBalanceRj + adjustmentTotal', $scope),
			0.001
		);
	}

	/**
	 * @test
	 * Precedence and parentheses, so `a - b * c` is not `(a - b) * c`.
	 */
	public function testPrecedenceAndParentheses(): void {
		$scope = ['a' => 10.0, 'b' => 2.0, 'c' => 3.0];

		$this->assertEqualsWithDelta(4.0, $this->evaluator->evaluate('a - b * c', $scope), 0.001);
		$this->assertEqualsWithDelta(24.0, $this->evaluator->evaluate('(a - b) * c', $scope), 0.001);
		$this->assertEqualsWithDelta(-4.0, $this->evaluator->evaluate('-a + b * c', $scope), 0.001);
	}

	/**
	 * @test
	 * The nested min() the innovatiebox nexus fraction needs.
	 *
	 * min(min(1.3 * (eigen + uitbesteed), totale) / totale, 1.0)
	 */
	public function testNestedMinWithDivision(): void {
		$scope = ['eigen' => 100.0, 'uitbesteed' => 100.0, 'totale' => 500.0];

		// 1.3 * 200 = 260; min(260, 500) = 260; 260/500 = 0.52; min(0.52, 1.0) = 0.52
		$this->assertEqualsWithDelta(
			0.52,
			$this->evaluator->evaluate('min(min(1.3 * (eigen + uitbesteed), totale) / totale, 1.0)', $scope),
			0.0001
		);

		// The cap must actually bind: 1.3 * 900 = 1170; min(1170, 500) = 500;
		// 500/500 = 1.0; min(1.0, 1.0) = 1.0
		$capped = ['eigen' => 500.0, 'uitbesteed' => 400.0, 'totale' => 500.0];
		$this->assertEqualsWithDelta(
			1.0,
			$this->evaluator->evaluate('min(min(1.3 * (eigen + uitbesteed), totale) / totale, 1.0)', $capped),
			0.0001
		);
	}

	/**
	 * @test
	 * An unknown alias RAISES, and names what is available.
	 *
	 * Resolving it to 0 would turn a typo into a plausible number: `a - typo`
	 * would quietly return `a`, which is exactly the failure this engine work
	 * exists to remove.
	 */
	public function testUnknownAliasThrowsAndNamesTheAvailableOnes(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/totalVATCollectd.*Available.*totalVATPaid/s');

		$this->evaluator->evaluate(
			'totalVATPaid - totalVATCollectd',
			['totalVATPaid' => 300.0]
		);
	}

	/**
	 * @test
	 * Division by zero yields null, not INF and not NAN.
	 *
	 * json_encode() refuses INF outright, and NAN compares false against
	 * everything — both travel a long way before anyone notices.
	 */
	public function testDivisionByZeroIsNullNotInfinity(): void {
		$result = $this->evaluator->evaluate('a / b', ['a' => 5.0, 'b' => 0.0]);

		$this->assertNull($result);
		$this->assertNotSame(INF, $result);
	}

	/**
	 * @test
	 * A null alias propagates as null rather than counting as zero.
	 */
	public function testNullAliasPropagates(): void {
		$this->assertNull($this->evaluator->evaluate('a + b', ['a' => 5.0, 'b' => null]));
		$this->assertNull($this->evaluator->evaluate('min(a, b)', ['a' => 5.0, 'b' => null]));
	}

	/**
	 * @test
	 * Anything outside the grammar is refused by name — there is no eval() here.
	 *
	 * These expressions come from register descriptors, which are DATA. eval()
	 * on data is arbitrary code execution.
	 */
	public function testRefusesEverythingOutsideTheGrammar(): void {
		$scope = ['a' => 1.0];

		foreach ([
			'phpinfo()',
			'a; system("id")',
			'$a + 1',
			'a->b',
			"a . 'x'",
			'a ** 2',
		] as $hostile) {
			try {
				$this->evaluator->evaluate($hostile, $scope);
				$this->fail(sprintf('Expected "%s" to be refused', $hostile));
			} catch (RuntimeException $e) {
				$this->assertNotSame('', $e->getMessage());
			}
		}
	}

	/**
	 * @test
	 * A non-numeric alias value raises rather than being coerced.
	 */
	public function testNonNumericAliasThrows(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/rather than a number/');

		$this->evaluator->evaluate('a + 1', ['a' => 'twelve']);
	}

	/**
	 * @test
	 * An empty or truncated expression raises rather than returning 0.
	 */
	public function testEmptyAndTruncatedExpressionsThrow(): void {
		foreach (['', '   ', 'a +', '(a', 'min(a)'] as $bad) {
			try {
				$this->evaluator->evaluate($bad, ['a' => 1.0]);
				$this->fail(sprintf('Expected "%s" to be refused', $bad));
			} catch (RuntimeException $e) {
				$this->assertNotSame('', $e->getMessage());
			}
		}
	}
}//end class
