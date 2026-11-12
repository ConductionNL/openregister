<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Service\Vocabulary\ConceptTreeWalk}.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Vocabulary
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/skos-concept-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Vocabulary;

use DateTimeImmutable;
use OCA\OpenRegister\Service\Vocabulary\ConceptReading;
use OCA\OpenRegister\Service\Vocabulary\ConceptRelations;
use OCA\OpenRegister\Service\Vocabulary\ConceptTreeWalk;
use PHPUnit\Framework\TestCase;

/**
 * A bounded tree walk: each step down costs one level and remembers where it has been.
 *
 * @covers \OCA\OpenRegister\Service\Vocabulary\ConceptTreeWalk
 * @uses   \OCA\OpenRegister\Service\Vocabulary\ConceptReading
 * @uses   \OCA\OpenRegister\Service\Vocabulary\ConceptRelations
 */
class ConceptTreeWalkTest extends TestCase {

	/**
	 * A walk with the given budget.
	 *
	 * @param int $remaining Levels left.
	 *
	 * @return ConceptTreeWalk
	 */
	private function walk(int $remaining): ConceptTreeWalk {
		return new ConceptTreeWalk(
			new ConceptRelations([]),
			new ConceptReading('nl', new DateTimeImmutable('2026-01-01')),
			$remaining
		);

	}//end walk()

	/**
	 * Descending is allowed only while levels remain.
	 *
	 * @return void
	 */
	public function testCanDescendOnlyWithBudget(): void {
		$this->assertTrue($this->walk(1)->canDescend());
		$this->assertFalse($this->walk(0)->canDescend());
		$this->assertFalse($this->walk(-1)->canDescend());

	}//end testCanDescendOnlyWithBudget()

	/**
	 * Below() spends a level, records the URI, shares collaborators and leaves the original alone.
	 *
	 * @return void
	 */
	public function testBelowIsImmutableAndRemembers(): void {
		$walk  = $this->walk(2);
		$child = $walk->below('urn:a');
		$grand = $child->below('urn:b');

		$this->assertNotSame($walk, $child);
		$this->assertSame(2, $walk->remaining);
		$this->assertSame(1, $child->remaining);
		$this->assertSame(0, $grand->remaining);
		$this->assertFalse($grand->canDescend());

		$this->assertFalse($walk->hasVisited('urn:a'));
		$this->assertTrue($child->hasVisited('urn:a'));
		$this->assertFalse($child->hasVisited('urn:b'));
		$this->assertTrue($grand->hasVisited('urn:a'));
		$this->assertTrue($grand->hasVisited('urn:b'));

		$this->assertSame($walk->relations, $grand->relations);
		$this->assertSame($walk->reading, $grand->reading);

	}//end testBelowIsImmutableAndRemembers()
}//end class
