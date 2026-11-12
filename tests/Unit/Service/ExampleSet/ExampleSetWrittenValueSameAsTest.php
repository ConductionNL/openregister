<?php

/**
 * Unit test for ExampleSetWrittenValue::sameAs(): a stored value equals what a
 * set wrote both as plain text and as the JSON text of a structure, and not
 * when either differs.
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
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\Service\ExampleSet\ExampleSetWrittenValue;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetWrittenValue
 * @uses   \OCA\OpenRegister\Service\ExampleSet\ExampleSetProof
 * @uses   \OCA\OpenRegister\Service\ExampleSet\ExampleSetProofPlace
 */
class ExampleSetWrittenValueSameAsTest extends TestCase {

	/**
	 * Plain text equals itself; a structure equals its JSON text either way round; differences do not.
	 *
	 * @return void
	 */
	public function testSameAsReadsJsonTextAsItsStructure(): void {
		$written = new ExampleSetWrittenValue();

		$this->assertTrue($written->sameAs(stored: 'Zaak', value: 'Zaak'));
		$this->assertFalse($written->sameAs(stored: 'Zaak', value: 'Andere zaak'));
		$this->assertTrue($written->sameAs(stored: '{"a":1}', value: ['a' => 1]));
		$this->assertTrue($written->sameAs(stored: ['a' => 1], value: '{"a":1}'));
		$this->assertTrue($written->sameAs(stored: '[1,2]', value: [1, 2]));
		$this->assertFalse($written->sameAs(stored: 'plain text', value: ['a' => 1]));
	}//end testSameAsReadsJsonTextAsItsStructure()
}//end class
