<?php

/**
 * InversedByNormaliser reduces each inversedBy declaration to the shape the schema stores.
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

use OCA\OpenRegister\Db\Schema\InversedByNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\Schema\InversedByNormaliser
 */
class InversedByNormaliserTest extends TestCase {

	/**
	 * Normalise one property.
	 *
	 * @param mixed $property The property.
	 *
	 * @return mixed
	 */
	private static function one(mixed $property): mixed {
		return (new InversedByNormaliser())->normalised(['p' => $property])['p'];
	}//end one()

	/**
	 * An object with an id collapses to the id, on the property and on its items.
	 *
	 * @return void
	 */
	public function testAnObjectWithAnIdCollapsesToTheId(): void {
		$this->assertSame(['inversedBy' => 'case'], self::one(['inversedBy' => ['id' => 'case', 'label' => 'Case']]));
		$this->assertSame(
			['type' => 'array', 'items' => ['inversedBy' => 'case']],
			self::one(['type' => 'array', 'items' => ['inversedBy' => ['id' => 'case']]])
		);
	}//end testAnObjectWithAnIdCollapsesToTheId()

	/**
	 * A string and a list are kept as they are.
	 *
	 * @return void
	 */
	public function testAStringAndAListAreKept(): void {
		$this->assertSame(['inversedBy' => 'case'], self::one(['inversedBy' => 'case']));
		$this->assertSame(['inversedBy' => ['a', 'b']], self::one(['inversedBy' => ['a', 'b']]));
	}//end testAStringAndAListAreKept()

	/**
	 * An object without an id, or a scalar that is not a string, is dropped.
	 *
	 * @return void
	 */
	public function testAnUnusableDeclarationIsDropped(): void {
		$this->assertSame(['type' => 'object'], self::one(['type' => 'object', 'inversedBy' => ['label' => 'Case']]));
		$this->assertSame(['type' => 'object'], self::one(['type' => 'object', 'inversedBy' => 5]));
		$this->assertSame(['items' => ['type' => 'string']], self::one(['items' => ['type' => 'string', 'inversedBy' => false]]));
	}//end testAnUnusableDeclarationIsDropped()

	/**
	 * A property whose own declaration is a kept list leaves its items untouched.
	 *
	 * @return void
	 */
	public function testASettledPropertyLeavesItsItemsAlone(): void {
		$property = ['inversedBy' => ['a'], 'items' => ['inversedBy' => ['label' => 'x']]];

		$this->assertSame($property, self::one($property));
	}//end testASettledPropertyLeavesItsItemsAlone()

	/**
	 * A property declared by id is settled too: it collapses, its items stay as given.
	 *
	 * @return void
	 */
	public function testAnIdDeclarationIsSettled(): void {
		$this->assertSame(
			['inversedBy' => 'whole', 'items' => ['inversedBy' => ['label' => 'x']]],
			self::one(['inversedBy' => ['id' => 'whole'], 'items' => ['inversedBy' => ['label' => 'x']]])
		);
	}//end testAnIdDeclarationIsSettled()

	/**
	 * A property whose own declaration is dropped still has its items normalised.
	 *
	 * @return void
	 */
	public function testAnUnsettledPropertyNormalisesItsItems(): void {
		$this->assertSame(
			['items' => ['inversedBy' => 'whole']],
			self::one(['inversedBy' => ['label' => 'y'], 'items' => ['inversedBy' => ['id' => 'whole']]])
		);
	}//end testAnUnsettledPropertyNormalisesItsItems()

	/**
	 * Entries that are not property definitions pass through, and the keys are kept.
	 *
	 * @return void
	 */
	public function testNonDefinitionsPassThrough(): void {
		$this->assertSame(
			['note' => 'text', 'p' => ['inversedBy' => 'x'], 'items' => 'not an array'],
			(new InversedByNormaliser())->normalised(['note' => 'text', 'p' => ['inversedBy' => ['id' => 'x']], 'items' => 'not an array'])
		);
		$this->assertSame(['items' => 'flat'], self::one(['items' => 'flat']));
	}//end testNonDefinitionsPassThrough()
}//end class
