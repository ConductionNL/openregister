<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Service\Vocabulary\ConceptHolding}.
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

use OCA\OpenRegister\Service\Vocabulary\ConceptHolding;
use PHPUnit\Framework\TestCase;

/**
 * What a concept is held as, and when it has no identity at all.
 *
 * @covers \OCA\OpenRegister\Service\Vocabulary\ConceptHolding
 */
class ConceptHoldingTest extends TestCase {

	/**
	 * URI and notation are trimmed strings, scheme kept verbatim.
	 *
	 * @return void
	 */
	public function testFromConceptTrims(): void {
		$holding = ConceptHolding::fromConcept(['uri' => ' urn:a ', 'notation' => 42], 'urn:scheme');

		$this->assertSame('urn:scheme', $holding->schemeUri);
		$this->assertSame('urn:a', $holding->uri);
		$this->assertSame('42', $holding->notation);
		$this->assertFalse($holding->isAnonymous());

	}//end testFromConceptTrims()

	/**
	 * Anonymous only when both URI and notation are empty.
	 *
	 * @return void
	 */
	public function testIsAnonymous(): void {
		$this->assertTrue(ConceptHolding::fromConcept([], 's')->isAnonymous());
		$this->assertTrue(ConceptHolding::fromConcept(['uri' => '  ', 'notation' => ''], 's')->isAnonymous());
		$this->assertFalse(ConceptHolding::fromConcept(['notation' => 'A1'], 's')->isAnonymous());
		$this->assertFalse(ConceptHolding::fromConcept(['uri' => 'urn:x'], 's')->isAnonymous());

	}//end testIsAnonymous()
}//end class
