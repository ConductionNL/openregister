<?php

declare(strict_types=1);

namespace Unit\Service\Calculation;

use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCA\OpenRegister\Service\Calculation\OperatorCatalogue;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The catalogue is the dispatch, checked.
 *
 * The descriptor table and the `match` that dispatches on it live in one
 * file, side by side. This test reads the match arms out of that file and
 * fails when the two sets drift, which is what turns "generated from the
 * evaluator's own dispatch" into a claim a build can refute.
 *
 * Covers the scenario "a newly added operator appears on its own", which the
 * spec excludes from e2e for exactly this reason.
 */
class OperatorCatalogueTest extends TestCase {
	/**
	 * @var OperatorCatalogue The published catalogue under test.
	 */
	private OperatorCatalogue $catalogue;

	/**
	 * Wire the collaborators this suite needs.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->catalogue = new OperatorCatalogue();
	}

	/**
	 * Read the operator keys the evaluator's match actually dispatches on.
	 *
	 * @return array<int, string> The operator keys, sorted.
	 */
	private function dispatchedOperators(): array {
		$file = (new ReflectionClass(CalculationEvaluator::class))->getFileName();
		$this->assertIsString(actual: $file);
		$source = (string)file_get_contents($file);

		$start = strpos($source, 'return match ($op) {');
		$end = strpos($source, '};//end match', (int)$start);
		$this->assertIsInt(actual: $start, message: 'the evaluator no longer has a single match dispatch');
		$this->assertIsInt(actual: $end, message: 'the evaluator match is no longer terminated by //end match');

		$block = substr($source, (int)$start, (int)$end - (int)$start);
		preg_match_all("/^\s*((?:'[^']+'\s*,\s*)*'[^']+')\s*=>/m", $block, $matches);

		$operators = [];
		foreach ($matches[1] as $group) {
			preg_match_all("/'([^']+)'/", $group, $keys);
			foreach ($keys[1] as $key) {
				$operators[] = $key;
			}
		}

		sort($operators);

		return $operators;
	}

	/**
	 * Catalogue matches the evaluator dispatch.
	 *
	 * @return void
	 */
	public function testCatalogueMatchesTheEvaluatorDispatch(): void {
		$dispatched = $this->dispatchedOperators();
		$published = $this->catalogue->operators();
		sort($published);

		$this->assertNotEmpty(actual: $dispatched, message: 'no match arms were parsed out of the evaluator');
		$this->assertSame(expected: $dispatched, actual: $published, message: 'the published catalogue and the evaluator dispatch have drifted apart');
	}

	/**
	 * Every row carries arity operands result and A sentence.
	 *
	 * @return void
	 */
	public function testEveryRowCarriesArityOperandsResultAndASentence(): void {
		foreach ($this->catalogue->all() as $row) {
			$this->assertNotSame(expected: '', actual: $row['op']);
			$this->assertNotSame(expected: '', actual: $row['arity'], message: $row['op'] . ' has no arity');
			$this->assertIsArray(actual: $row['operands'], message: $row['op'] . ' has no operand list');
			$this->assertNotSame(expected: '', actual: $row['result'], message: $row['op'] . ' has no result type');
			$this->assertNotSame(expected: '', actual: $row['description'], message: $row['op'] . ' has no description');
			$this->assertNotSame(expected: '', actual: $row['category'], message: $row['op'] . ' has no category');
		}
	}

	/**
	 * Catalogue answers for A known and an unknown operator.
	 *
	 * @return void
	 */
	public function testCatalogueAnswersForAKnownAndAnUnknownOperator(): void {
		$this->assertTrue(condition: $this->catalogue->has('dateAdd'));
		$this->assertFalse(condition: $this->catalogue->has('frobnicate'));
	}

	/**
	 * Categories are the distinct categories of the rows.
	 *
	 * @return void
	 */
	public function testCategoriesAreTheDistinctCategoriesOfTheRows(): void {
		$categories = $this->catalogue->categories();
		$this->assertContains(needle: 'date', haystack: $categories);
		$this->assertContains(needle: 'arithmetic', haystack: $categories);
		$this->assertSame(expected: array_values(array_unique($categories)), actual: $categories);
	}
}
