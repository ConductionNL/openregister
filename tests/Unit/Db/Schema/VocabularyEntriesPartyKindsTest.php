<?php

/**
 * VocabularyEntries validates and reads party kinds, with their roles and identity shapes.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db\Schema
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\Schema;

use InvalidArgumentException;
use OCA\OpenRegister\Db\Schema\VocabularyEntries;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\Schema\VocabularyEntries
 */
class VocabularyEntriesPartyKindsTest extends TestCase {

	/**
	 * A party kind keeps distinct, trimmed roles and identity shapes and drops empty lists.
	 *
	 * @return void
	 */
	public function testAPartyKindKeepsDistinctTrimmedLists(): void {
		$kinds = (new VocabularyEntries())->validatePartyKindsValue([
			[
				'key' => ' person ',
				'label' => '',
				'description' => ' A natural person ',
				'roles' => ['applicant', ' applicant', '', 'witness'],
				'identityShapes' => 'bsn',
			],
			'organisation',
		]);

		$this->assertSame(
			[
				['key' => 'person', 'label' => 'person', 'description' => 'A natural person', 'roles' => ['applicant', 'witness']],
				['key' => 'organisation', 'label' => 'organisation'],
			],
			$kinds
		);
	}//end testAPartyKindKeepsDistinctTrimmedLists()

	/**
	 * A party kind with identity shapes keeps them.
	 *
	 * @return void
	 */
	public function testAPartyKindKeepsIdentityShapes(): void {
		$kinds = (new VocabularyEntries())->validatePartyKindsValue([['key' => 'person', 'identityShapes' => ['bsn', 'bsn', 'email']]]);

		$this->assertSame([['key' => 'person', 'label' => 'person', 'identityShapes' => ['bsn', 'email']]], $kinds);
	}//end testAPartyKindKeepsIdentityShapes()

	/**
	 * Party kinds that are not a list are refused with the party-kinds wording.
	 *
	 * @return void
	 */
	public function testPartyKindsThatAreNotAListAreRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage("Configuration 'partyKinds' must be a list of party kinds");

		(new VocabularyEntries())->validatePartyKindsValue(['person' => ['key' => 'person']]);
	}//end testPartyKindsThatAreNotAListAreRefused()

	/**
	 * A party kind without a usable key is refused.
	 *
	 * @return void
	 */
	public function testAPartyKindWithoutAKeyIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage("Each 'partyKinds' entry needs a non-empty key of at most 64 characters");

		(new VocabularyEntries())->validatePartyKindsValue([['label' => 'Nameless']]);
	}//end testAPartyKindWithoutAKeyIsRefused()

	/**
	 * Reading configured party kinds skips unusable entries.
	 *
	 * @return void
	 */
	public function testConfiguredPartyKindsSkipUnusableEntries(): void {
		$kinds = (new VocabularyEntries())->configuredVocabulary(
			['partyKinds' => [['key' => 'person', 'roles' => ['applicant']], 42, ['key' => str_repeat('k', 65)]]],
			'partyKinds'
		);

		$this->assertSame([['key' => 'person', 'label' => 'person', 'roles' => ['applicant']]], $kinds);
	}//end testConfiguredPartyKindsSkipUnusableEntries()
	/**
	 * A key named twice is refused.
	 *
	 * @return void
	 */
	public function testAKeyNamedTwiceIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage("'partyKinds' names the key 'person' twice");

		(new VocabularyEntries())->validatePartyKindsValue(['person', ['key' => ' person', 'roles' => ['x']]]);
	}//end testAKeyNamedTwiceIsRefused()

	/**
	 * Configured link roles read as link roles, without party-kind lists.
	 *
	 * @return void
	 */
	public function testConfiguredLinkRolesIgnorePartyKindLists(): void {
		$roles = (new VocabularyEntries())->configuredVocabulary(['linkRoles' => [['key' => 'author', 'roles' => ['x']]]], 'linkRoles');

		$this->assertSame([['key' => 'author', 'label' => 'author']], $roles);
	}//end testConfiguredLinkRolesIgnorePartyKindLists()

	/**
	 * A configuration that is not an array, or lacks the vocabulary, reads as none.
	 *
	 * @return void
	 */
	public function testAMissingConfigurationReadsAsNone(): void {
		$vocabulary = new VocabularyEntries();

		$this->assertSame([], $vocabulary->configuredVocabulary('not an array', 'partyKinds'));
		$this->assertSame([], $vocabulary->configuredVocabulary(['partyKinds' => 'person'], 'partyKinds'));
	}//end testAMissingConfigurationReadsAsNone()
}//end class
