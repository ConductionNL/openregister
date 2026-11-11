<?php

/**
 * Unit test for RelationRowFields.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Relation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Relation;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Relation\RelationRowFields;
use PHPUnit\Framework\TestCase;

/**
 * The row-field shapes ObjectRelationService writes.
 */
class RelationRowFieldsTest extends TestCase {

	/**
	 * An object link carries both ends, numeric references as ints and anything else as null.
	 *
	 * @return void
	 */
	public function testAnObjectLinkCarriesBothEnds(): void {
		$from = new ObjectEntity();
		$from->setUuid('f');
		$from->setRegister('3');
		$to = new ObjectEntity();
		$to->setUuid('t');
		$to->setRegister('x');
		$to->setSchema(4);

		$this->assertSame(
			[
				'sourceUuid' => 'f',
				'sourceRegister' => 3,
				'sourceSchema' => null,
				'targetUuid' => 't',
				'targetRegister' => null,
				'targetSchema' => 4,
				'kind' => 'object',
				'origin' => 'split',
			],
			(new RelationRowFields())->splitLink(from: $from, to: $to)
		);
	}//end testAnObjectLinkCarriesBothEnds()

	/**
	 * A derivation's link names its own origin.
	 *
	 * @return void
	 */
	public function testADerivedLinkNamesTheDeriveOrigin(): void {
		$child  = new ObjectEntity();
		$parent = new ObjectEntity();
		$child->setUuid('c');
		$parent->setUuid('p');

		$row = (new RelationRowFields())->derivedLink(from: $child, to: $parent);
		$this->assertSame(['c', 'p', 'derive'], [$row['sourceUuid'], $row['targetUuid'], $row['origin']]);
		$this->assertSame('origin', array_key_last($row));
	}//end testADerivedLinkNamesTheDeriveOrigin()

	/**
	 * Only a present, non-null, non-empty value counts as a value.
	 *
	 * @return void
	 */
	public function testOnlyAPresentNonEmptyValueCounts(): void {
		$fields = new RelationRowFields();
		$data   = ['a' => 'x', 'b' => '', 'c' => null, 'd' => 0];

		$this->assertSame(
			[true, false, false, true, false],
			array_map(static fn (string $key): bool => $fields->hasValue(data: $data, property: $key), ['a', 'b', 'c', 'd', 'e'])
		);
	}//end testOnlyAPresentNonEmptyValueCounts()

	/**
	 * An external target is trimmed, titled by its address when untitled, and refused when not an address.
	 *
	 * @return void
	 */
	public function testAnExternalTargetIsTitledOrRefused(): void {
		$fields = new RelationRowFields();

		$this->assertSame(
			['targetUrl' => 'https://example.org', 'targetTitle' => 'https://example.org'],
			$fields->externalTarget(url: ' https://example.org ', title: ' ')
		);
		$this->assertSame(
			['targetUrl' => 'https://example.org', 'targetTitle' => 'Ex'],
			$fields->externalTarget(url: 'https://example.org', title: ' Ex ')
		);

		$this->expectException(InvalidArgumentException::class);
		$fields->externalTarget(url: 'not an address', title: null);
	}//end testAnExternalTargetIsTitledOrRefused()

	/**
	 * The far side's scope swaps the ends, and an empty record reads as null.
	 *
	 * @return void
	 */
	public function testScopeReversalAndEmptyRecord(): void {
		$fields = new RelationRowFields();

		$this->assertSame(
			['sourceRegister' => null, 'sourceSchema' => 9, 'targetRegister' => 1, 'targetSchema' => null],
			$fields->reversedScope(scope: ['sourceRegister' => 1, 'targetSchema' => 9])
		);
		$this->assertNull($fields->recordOf(inherited: []));
		$this->assertSame(['a' => 1], $fields->recordOf(inherited: ['a' => 1]));
		$this->assertSame(['type' => 'b'], $fields->descriptorOfType(descriptors: [['type' => 'a'], ['type' => 'b'], ['type' => 'b', 'x' => 1]], type: 'b'));
		$this->assertNull($fields->descriptorOfType(descriptors: [['label' => 'a']], type: 'a'));
	}//end testScopeReversalAndEmptyRecord()
}//end class
