<?php

declare(strict_types=1);

namespace Unit\Service\Quality;

use OCA\OpenRegister\Search\Service\Quality\ComparedValues;
use OCA\OpenRegister\Search\Service\Quality\SimilarityCalculator;
use PHPUnit\Framework\TestCase;

class SimilarityCalculatorTest extends TestCase {
	private SimilarityCalculator $calc;

	protected function setUp(): void {
		$this->calc = new SimilarityCalculator();
	}

	public function testExact(): void {
		$this->assertSame(1.0, $this->calc->similarity('exact', new ComparedValues('Acme', 'Acme')));
		$this->assertSame(0.0, $this->calc->similarity('exact', new ComparedValues('Acme', 'acme')));
	}

	public function testNormalizedIgnoresCaseWhitespaceAccents(): void {
		$this->assertSame(1.0, $this->calc->similarity('normalized', new ComparedValues('Acme  BV', 'acme bv')));
		$this->assertSame(1.0, $this->calc->similarity('normalized', new ComparedValues('Café', 'cafe')));
		$this->assertSame(0.0, $this->calc->similarity('normalized', new ComparedValues('Acme', 'Globex')));
	}

	public function testLevenshteinNearMatch(): void {
		// "Jon Smith" vs "John Smith": 1 edit over 10 chars => 0.9.
		$score = $this->calc->similarity('levenshtein', new ComparedValues('Jon Smith', 'John Smith'));
		$this->assertGreaterThan(0.85, $score);
		$this->assertLessThanOrEqual(1.0, $score);
	}

	public function testLevenshteinFarMatch(): void {
		$this->assertLessThan(0.5, $this->calc->similarity('levenshtein', new ComparedValues('Acme', 'Globex Corp')));
	}

	public function testUnknownMethodIsZero(): void {
		$this->assertSame(0.0, $this->calc->similarity('jaro', new ComparedValues('a', 'a')));
	}

	public function testNonScalarOperandsAreZero(): void {
		$this->assertSame(0.0, $this->calc->similarity('exact', new ComparedValues(['x'], 'a')));
		$this->assertSame(0.0, $this->calc->similarity('exact', new ComparedValues(null, null)));
	}

	public function testBlockingTokenExactVsNormalized(): void {
		$this->assertSame('Acme BV', $this->calc->blockingToken('exact', 'Acme BV'));
		$this->assertSame('acme bv', $this->calc->blockingToken('normalized', 'Acme  BV'));
		$this->assertSame('', $this->calc->blockingToken('normalized', null));
	}
}
