<?php

/**
 * FirstPassWritesTest: pass 2 recognises only what pass 1 of the same import wrote, unchanged.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- short positional calls read better in assertions.

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use OCA\OpenRegister\Service\Configuration\FirstPassWrites;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Configuration\FirstPassWrites
 *
 * @spec openspec/specs/data-import-export/spec.md#requirement-the-second-schema-pass-must-write-only-what-resolving-references-changed
 */
class FirstPassWritesTest extends TestCase {

	private const DEFINITION = ['title' => 'Invoice', 'properties' => ['amount' => ['type' => 'number']]];

	public function testPassTwoRecognisesTheDefinitionPassOneWrote(): void {
		$writes = new FirstPassWrites();
		$writes->beginFirstPass();
		$writes->remember(7, self::DEFINITION);
		$writes->beginSecondPass();

		$this->assertTrue($writes->wroteTheSame(7, self::DEFINITION));
	}//end testPassTwoRecognisesTheDefinitionPassOneWrote()

	public function testADefinitionThatResolvingReferencesChangedIsWrittenAgain(): void {
		$writes = new FirstPassWrites();
		$writes->beginFirstPass();
		$writes->remember(7, self::DEFINITION);
		$writes->beginSecondPass();

		$resolved = self::DEFINITION;
		$resolved['properties']['customer'] = ['$ref' => 12];

		$this->assertFalse($writes->wroteTheSame(7, $resolved));
	}//end testADefinitionThatResolvingReferencesChangedIsWrittenAgain()

	public function testASchemaPassOneDidNotWriteIsWrittenByPassTwo(): void {
		$writes = new FirstPassWrites();
		$writes->beginFirstPass();
		$writes->remember(7, self::DEFINITION);
		$writes->beginSecondPass();

		$this->assertFalse($writes->wroteTheSame(8, self::DEFINITION));
		$this->assertFalse($writes->wroteTheSame(null, self::DEFINITION));
	}//end testASchemaPassOneDidNotWriteIsWrittenByPassTwo()

	public function testNothingIsRecognisedOutsidePassTwo(): void {
		$writes = new FirstPassWrites();
		$writes->remember(7, self::DEFINITION);
		$this->assertFalse($writes->wroteTheSame(7, self::DEFINITION), 'idle');

		$writes->beginFirstPass();
		$writes->remember(7, self::DEFINITION);
		$this->assertFalse($writes->wroteTheSame(7, self::DEFINITION), 'during pass 1');

		$writes->beginSecondPass();
		$writes->end();
		$this->assertFalse($writes->wroteTheSame(7, self::DEFINITION), 'after the import');
	}//end testNothingIsRecognisedOutsidePassTwo()

	public function testAWriteOfPassTwoIsNotRemembered(): void {
		$writes = new FirstPassWrites();
		$writes->beginFirstPass();
		$writes->beginSecondPass();
		$writes->remember(7, self::DEFINITION);

		$this->assertFalse($writes->wroteTheSame(7, self::DEFINITION));
	}//end testAWriteOfPassTwoIsNotRemembered()

	public function testANewImportForgetsTheOneBefore(): void {
		$writes = new FirstPassWrites();
		$writes->beginFirstPass();
		$writes->remember(7, self::DEFINITION);
		$writes->beginFirstPass();
		$writes->beginSecondPass();

		$this->assertFalse($writes->wroteTheSame(7, self::DEFINITION));
	}//end testANewImportForgetsTheOneBefore()
}//end class
