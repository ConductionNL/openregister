<?php

/**
 * Tests for the example-set proof: which declared paths a read-back did not keep.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\Service\ExampleSet\ExampleSetProof;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetProof
 */
class ExampleSetProofTest extends TestCase {

	/**
	 * A language map read back in the reader's language arrived.
	 *
	 * @return void
	 */
	public function testALanguageMapReadInOneLanguageIsKept(): void {
		$lost = (new ExampleSetProof())->lostPaths(
			declared: ['name' => 'bouwkosten', 'title' => ['nl' => 'Bouwkosten', 'en' => 'Building costs']],
			stored: ['name' => 'bouwkosten', 'title' => 'Bouwkosten']
		);

		$this->assertSame(expected: [], actual: $lost);
	}//end testALanguageMapReadInOneLanguageIsKept()

	/**
	 * A language map read back as a text that is none of its values was not kept.
	 *
	 * @return void
	 */
	public function testALanguageMapReadAsAnotherTextIsLost(): void {
		$lost = (new ExampleSetProof())->lostPaths(
			declared: ['title' => ['nl' => 'Bouwkosten', 'en' => 'Building costs']],
			stored: ['title' => 'Bouwsom']
		);

		$this->assertSame(expected: ['title.nl', 'title.en'], actual: $lost);
	}//end testALanguageMapReadAsAnotherTextIsLost()

	/**
	 * A map whose keys are not languages is compared key by key, as before.
	 *
	 * @return void
	 */
	public function testAMapOfOtherKeysIsComparedKeyByKey(): void {
		$lost = (new ExampleSetProof())->lostPaths(
			declared: ['address' => ['street' => 'Lindelaan', 'number' => '14']],
			stored: ['address' => 'Lindelaan']
		);

		$this->assertSame(expected: ['address.street', 'address.number'], actual: $lost);
	}//end testAMapOfOtherKeysIsComparedKeyByKey()
}//end class
