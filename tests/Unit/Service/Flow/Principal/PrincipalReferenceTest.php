<?php

/**
 * Reading who a step is asking, from what an author actually stored.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Principal
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Flow\Principal;

use OCA\OpenRegister\Service\Flow\Principal\PrincipalReference;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see PrincipalReference}.
 *
 * @covers \OCA\OpenRegister\Service\Flow\Principal\PrincipalReference
 */
final class PrincipalReferenceTest extends TestCase {

	/**
	 * 🔴 A BARE STRING STILL MEANS A USER.
	 *
	 * Reading it as "try every type" would be friendlier and would silently
	 * widen who may answer on every flow already stored. The guard being
	 * replaced tries uid first, so `user` is the reading that preserves
	 * today's behaviour rather than quietly granting more.
	 *
	 * @return void
	 */
	public function testABareStringIsAUser(): void {
		$reference = PrincipalReference::from(value: 'jdoe');

		$this->assertNotNull($reference);
		$this->assertSame('user', $reference->type);
		$this->assertSame('jdoe', $reference->id);
	}//end testABareStringIsAUser()

	/**
	 * A typed reference keeps its type.
	 *
	 * @return void
	 */
	public function testATypedReferenceKeepsItsType(): void {
		$reference = PrincipalReference::from(value: ['type' => 'group', 'id' => 'bezwaar']);

		$this->assertSame('group', $reference->type);
		$this->assertSame('bezwaar', $reference->id);
		$this->assertSame('group:bezwaar', (string)$reference);
		$this->assertSame(['type' => 'group', 'id' => 'bezwaar'], $reference->jsonSerialize());
	}//end testATypedReferenceKeepsItsType()

	/**
	 * A reference to nobody is null, not an empty reference.
	 *
	 * Making one would push the emptiness down to the resolver, which would
	 * then report "resolved to no users" for something that was never a
	 * reference at all — and that message fails a step.
	 *
	 * @return void
	 */
	public function testSomethingWithNoIdIsNotAReference(): void {
		$this->assertNull(PrincipalReference::from(value: ''));
		$this->assertNull(PrincipalReference::from(value: '   '));
		$this->assertNull(PrincipalReference::from(value: ['type' => 'group']));
		$this->assertNull(PrincipalReference::from(value: null));
		$this->assertNull(PrincipalReference::from(value: 42));
	}//end testSomethingWithNoIdIsNotAReference()

	/**
	 * A list may mix types, and drops what it cannot read.
	 *
	 * @return void
	 */
	public function testAListMixesTypesAndDropsTheUnreadable(): void {
		$references = PrincipalReference::listFrom(
			value: [
				'jdoe',
				['type' => 'group', 'id' => 'bezwaar'],
				['type' => 'position', 'id' => 'chair'],
				'',
				['type' => 'group'],
			]
		);

		$this->assertSame(
			['user:jdoe', 'group:bezwaar', 'position:chair'],
			array_map(static fn ($r): string => (string)$r, $references)
		);
	}//end testAListMixesTypesAndDropsTheUnreadable()

	/**
	 * 🔴 A SINGLE `{type, id}` MAP IS ONE REFERENCE, NOT TWO.
	 *
	 * A map is itself an array, so a list reader that does not check for
	 * sequential keys reads its two VALUES as two references: `group` and
	 * `bezwaar`, both as users. That silently assigns a task to a user called
	 * "group".
	 *
	 * @return void
	 */
	public function testASingleMapIsOneReferenceNotTwo(): void {
		$references = PrincipalReference::listFrom(value: ['type' => 'group', 'id' => 'bezwaar']);

		$this->assertCount(1, $references);
		$this->assertSame('group:bezwaar', (string)$references[0]);
	}//end testASingleMapIsOneReferenceNotTwo()

	/**
	 * A single string is a list of one, because the author meant the same thing.
	 *
	 * @return void
	 */
	public function testASingleStringIsAListOfOne(): void {
		$references = PrincipalReference::listFrom(value: 'jdoe');

		$this->assertCount(1, $references);
		$this->assertSame('user:jdoe', (string)$references[0]);
	}//end testASingleStringIsAListOfOne()

	/**
	 * 🔴 A LEGACY FIELD'S NAME IS WHAT TYPES ITS ENTRIES.
	 *
	 * `candidateGroups` holds bare group names. Reading them as users would
	 * silently move who may answer on every flow already stored — the exact
	 * regression the typed reference exists to prevent.
	 *
	 * @return void
	 */
	public function testALegacyFieldsNameTypesItsBareEntries(): void {
		$references = PrincipalReference::listOfType(
			value: ['bezwaar', 'college'],
			type: 'group'
		);

		$this->assertSame(
			['group:bezwaar', 'group:college'],
			array_map(static fn ($r): string => (string)$r, $references)
		);
	}//end testALegacyFieldsNameTypesItsBareEntries()

	/**
	 * An entry that names its own type keeps it, even in a legacy field.
	 *
	 * @return void
	 */
	public function testAnEntryThatNamesItsOwnTypeKeepsIt(): void {
		$references = PrincipalReference::listOfType(
			value: ['bezwaar', ['type' => 'position', 'id' => 'chair']],
			type: 'group'
		);

		$this->assertSame(
			['group:bezwaar', 'position:chair'],
			array_map(static fn ($r): string => (string)$r, $references)
		);
	}//end testAnEntryThatNamesItsOwnTypeKeepsIt()

	/**
	 * The legacy `value` spelling is still read.
	 *
	 * @return void
	 */
	public function testTheLegacyValueSpellingIsStillRead(): void {
		$reference = PrincipalReference::from(value: ['type' => 'group', 'value' => 'bezwaar']);

		$this->assertSame('group:bezwaar', (string)$reference);
	}//end testTheLegacyValueSpellingIsStillRead()
}//end class
