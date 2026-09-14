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
	private OperatorCatalogue $catalogue;

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
		$this->assertIsString($file);
		$source = (string)file_get_contents($file);

		$start = strpos($source, 'return match ($op) {');
		$end = strpos($source, '};//end match', (int)$start);
		$this->assertIsInt($start, 'the evaluator no longer has a single match dispatch');
		$this->assertIsInt($end, 'the evaluator match is no longer terminated by //end match');

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

	public function testCatalogueMatchesTheEvaluatorDispatch(): void {
		$dispatched = $this->dispatchedOperators();
		$published = $this->catalogue->operators();
		sort($published);

		$this->assertNotEmpty($dispatched, 'no match arms were parsed out of the evaluator');
		$this->assertSame(
			$dispatched,
			$published,
			'the published catalogue and the evaluator dispatch have drifted apart'
		);
	}

	public function testEveryRowCarriesArityOperandsResultAndASentence(): void {
		foreach ($this->catalogue->all() as $row) {
			$this->assertNotSame('', $row['op']);
			$this->assertNotSame('', $row['arity'], $row['op'] . ' has no arity');
			$this->assertIsArray($row['operands'], $row['op'] . ' has no operand list');
			$this->assertNotSame('', $row['result'], $row['op'] . ' has no result type');
			$this->assertNotSame('', $row['description'], $row['op'] . ' has no description');
			$this->assertNotSame('', $row['category'], $row['op'] . ' has no category');
		}
	}

	public function testCatalogueAnswersForAKnownAndAnUnknownOperator(): void {
		$this->assertTrue($this->catalogue->has('dateAdd'));
		$this->assertFalse($this->catalogue->has('frobnicate'));
	}

	public function testCategoriesAreTheDistinctCategoriesOfTheRows(): void {
		$categories = $this->catalogue->categories();
		$this->assertContains('date', $categories);
		$this->assertContains('arithmetic', $categories);
		$this->assertSame(array_values(array_unique($categories)), $categories);
	}
}
