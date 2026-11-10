<?php

/**
 * MagicColumnDriftTest: which required columns a magic table lacks, shares and stores under another type.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit mock helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper\MagicColumnDrift;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicColumnDrift
 */
class MagicColumnDriftTest extends TestCase {

	/**
	 * A drift where `title` and `meta_data` exist and `firstName`, `amount_eur` do not.
	 *
	 * @return MagicColumnDrift
	 */
	private function drift(): MagicColumnDrift {
		return new MagicColumnDrift(
			['title' => ['type' => 'VARCHAR'], 'meta_data' => ['type' => 'jsonb']],
			[
				'title' => ['type' => 'string'],
				'metaData' => ['type' => 'json_ordered'],
				'firstName' => ['type' => 'string'],
				'amount' => ['type' => 'decimal', 'name' => 'amount_eur'],
			]
		);
	}//end drift()

	public function testMissingListsRequiredColumnsAbsentUnderTheirPhysicalName(): void {
		$this->assertSame(
			['firstName' => ['type' => 'string'], 'amount' => ['type' => 'decimal', 'name' => 'amount_eur']],
			$this->drift()->missing()
		);
	}//end testMissingListsRequiredColumnsAbsentUnderTheirPhysicalName()

	public function testNothingIsMissingWhenEveryColumnExists(): void {
		$drift = new MagicColumnDrift(['amount_eur' => []], ['amount' => ['type' => 'decimal', 'name' => 'amount_eur']]);

		$this->assertSame([], $drift->missing());
	}//end testNothingIsMissingWhenEveryColumnExists()

	public function testSharedPairsEachExistingColumnWithItsDefinition(): void {
		$this->assertSame(
			[['title', ['type' => 'string']], ['meta_data', ['type' => 'json_ordered']]],
			$this->drift()->shared()
		);
	}//end testSharedPairsEachExistingColumnWithItsDefinition()

	public function testRequiredNamesUsesTheExplicitNameBeforeTheSanitizedProperty(): void {
		$this->assertSame(
			['title' => true, 'meta_data' => true, 'first_name' => true, 'amount_eur' => true],
			$this->drift()->requiredNames()
		);
	}//end testRequiredNamesUsesTheExplicitNameBeforeTheSanitizedProperty()

	public function testStoredAsMatchesTheDeclaredTypeAndACaseFoldedLiveType(): void {
		$drift = $this->drift();

		$this->assertSame(['meta_data'], $drift->storedAs('json_ordered', ['jsonb']));
		$this->assertSame(['title'], $drift->storedAs('string', ['varchar']));
		$this->assertSame([], $drift->storedAs('string', ['json', 'jsonb']));
	}//end testStoredAsMatchesTheDeclaredTypeAndACaseFoldedLiveType()
}//end class
