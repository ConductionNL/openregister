<?php

/**
 * CascadeRules: which properties cascade, how a stored array is tracked, and
 * what a cascade writes back into the parent's data.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use OCA\OpenRegister\Service\Object\SaveObject\CascadeRules;
use OCA\OpenRegister\Service\Object\SaveObject\CascadeRun;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Object\SaveObject\CascadeRules
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\CascadeRun
 */
class CascadeRulesTest extends TestCase {

	/**
	 * An object property cascades when it has a $ref and an inverse or cascade handling, and does not write back.
	 *
	 * @return void
	 */
	public function testWhichObjectPropertiesCascade(): void {
		self::assertTrue(CascadeRules::isObjectProperty(property: ['type' => 'object', '$ref' => 'note', 'inversedBy' => 'case']));
		self::assertTrue(
			CascadeRules::isObjectProperty(property: ['type' => 'object', '$ref' => 'note', 'objectConfiguration' => ['handling' => 'cascade']])
		);
		self::assertFalse(CascadeRules::isObjectProperty(property: ['type' => 'object', '$ref' => 'note']));
		self::assertFalse(CascadeRules::isObjectProperty(property: ['type' => 'object', 'inversedBy' => 'case']));
		self::assertFalse(CascadeRules::isObjectProperty(property: ['type' => 'string', '$ref' => 'note', 'inversedBy' => 'case']));
		self::assertFalse(
			CascadeRules::isObjectProperty(property: ['type' => 'object', '$ref' => 'note', 'inversedBy' => 'case', 'writeBack' => true])
		);
	}//end testWhichObjectPropertiesCascade()

	/**
	 * An array property cascades when it or its items declare a $ref and a link, and neither writes back.
	 *
	 * @return void
	 */
	public function testWhichArrayPropertiesCascade(): void {
		self::assertTrue(CascadeRules::isArrayProperty(property: ['type' => 'array', 'items' => ['$ref' => 'note', 'inversedBy' => 'case']]));
		self::assertTrue(CascadeRules::isArrayProperty(property: ['type' => 'array', '$ref' => 'note', 'inversedBy' => 'case']));
		self::assertTrue(
			CascadeRules::isArrayProperty(property: ['type' => 'array', 'items' => ['$ref' => 'note', 'objectConfiguration' => ['handling' => 'cascade']]])
		);
		self::assertTrue(
			CascadeRules::isArrayProperty(property: ['type' => 'array', '$ref' => 'note', 'objectConfiguration' => ['handling' => 'related-object']])
		);
		self::assertTrue(
			CascadeRules::isArrayProperty(property: ['type' => 'array', 'items' => ['$ref' => 'note', 'objectConfiguration' => ['handling' => 'related-schema']]])
		);

		self::assertFalse(CascadeRules::isArrayProperty(property: ['type' => 'array', 'items' => ['$ref' => 'note']]));
		self::assertFalse(CascadeRules::isArrayProperty(property: ['type' => 'array', 'items' => ['inversedBy' => 'case']]));
		self::assertFalse(CascadeRules::isArrayProperty(property: ['type' => 'object', 'items' => ['$ref' => 'note', 'inversedBy' => 'case']]));
		self::assertFalse(
			CascadeRules::isArrayProperty(property: ['type' => 'array', 'writeBack' => true, 'items' => ['$ref' => 'note', 'inversedBy' => 'case']])
		);
		self::assertFalse(
			CascadeRules::isArrayProperty(property: ['type' => 'array', 'items' => ['$ref' => 'note', 'inversedBy' => 'case', 'writeBack' => true]])
		);
	}//end testWhichArrayPropertiesCascade()

	/**
	 * A related array tracks the string uuids it stored, and is marked related.
	 *
	 * @return void
	 */
	public function testARelatedArrayTracksItsStoredUuids(): void {
		$run = $this->cascadeRun(
			definition: ['type' => 'array', 'items' => ['objectConfiguration' => ['handling' => 'related-object']]],
			stored: ['notes' => ['u1', 7, 'u2', null]]
		);

		CascadeRules::classifyArray(run: $run);

		self::assertTrue($run->isRelated);
		self::assertSame(['u1', 'u2'], $run->oldUuids);
	}//end testARelatedArrayTracksItsStoredUuids()

	/**
	 * A cascading array tracks its stored uuids without being related.
	 *
	 * @return void
	 */
	public function testACascadingArrayTracksItsStoredUuids(): void {
		$run = $this->cascadeRun(definition: ['objectConfiguration' => ['handling' => 'cascade']], stored: ['notes' => ['u1']]);

		CascadeRules::classifyArray(run: $run);

		self::assertFalse($run->isRelated);
		self::assertSame(['u1'], $run->oldUuids);
	}//end testACascadingArrayTracksItsStoredUuids()

	/**
	 * An untracked array, or a stored value that is no list, tracks nothing and clears what was there.
	 *
	 * @return void
	 */
	public function testAnUntrackedArrayTracksNothing(): void {
		$untracked = $this->cascadeRun(definition: ['items' => ['inversedBy' => 'case']], stored: ['notes' => ['u1']]);
		$untracked->oldUuids = ['stale'];
		CascadeRules::classifyArray(run: $untracked);
		self::assertSame([], $untracked->oldUuids);

		$scalar = $this->cascadeRun(definition: ['objectConfiguration' => ['handling' => 'cascade']], stored: ['notes' => 'u1']);
		CascadeRules::classifyArray(run: $scalar);
		self::assertSame([], $scalar->oldUuids);
	}//end testAnUntrackedArrayTracksNothing()

	/**
	 * A single cascade without an inverse, or one that writes back, stores the created uuid on the parent.
	 *
	 * @return void
	 */
	public function testASingleResultIsStoredWithoutAnInverseOrWithWriteBack(): void {
		$plain = $this->cascadeRun(definition: [], data: ['notes' => ['title' => 'x']]);
		CascadeRules::applySingleResult(run: $plain, createdUuid: 'u9');
		self::assertSame(['notes' => 'u9'], $plain->data);

		$writeBack = $this->cascadeRun(definition: ['inversedBy' => 'case', 'writeBack' => true], data: ['notes' => ['title' => 'x']]);
		CascadeRules::applySingleResult(run: $writeBack, createdUuid: null);
		self::assertSame(['notes' => null], $writeBack->data);
	}//end testASingleResultIsStoredWithoutAnInverseOrWithWriteBack()

	/**
	 * A single cascade with an inverse and no write-back leaves the parent without the property.
	 *
	 * @return void
	 */
	public function testASingleResultWithAnInverseIsDropped(): void {
		$run = $this->cascadeRun(definition: ['inversedBy' => 'case'], data: ['notes' => ['title' => 'x'], 'title' => 'case']);
		CascadeRules::applySingleResult(run: $run, createdUuid: 'u9');

		self::assertSame(['title' => 'case'], $run->data);
	}//end testASingleResultWithAnInverseIsDropped()

	/**
	 * An array cascade stores its uuids unless an inverse holds them and nothing writes back.
	 *
	 * @return void
	 */
	public function testAnArrayResultIsStoredUnlessTheInverseHoldsIt(): void {
		$plain = $this->cascadeRun(definition: [], data: ['notes' => [['title' => 'x']]]);
		CascadeRules::applyArrayResult(run: $plain, createdUuids: ['u1', 'u2']);
		self::assertSame(['notes' => ['u1', 'u2']], $plain->data);

		$itemWriteBack = $this->cascadeRun(definition: ['items' => ['inversedBy' => 'case', 'writeBack' => true]], data: ['notes' => []]);
		CascadeRules::applyArrayResult(run: $itemWriteBack, createdUuids: ['u1']);
		self::assertSame(['notes' => ['u1']], $itemWriteBack->data);

		$inverse = $this->cascadeRun(definition: ['items' => ['inversedBy' => 'case']], data: ['notes' => [['title' => 'x']], 'title' => 'case']);
		CascadeRules::applyArrayResult(run: $inverse, createdUuids: ['u1']);
		self::assertSame(['title' => 'case'], $inverse->data);
	}//end testAnArrayResultIsStoredUnlessTheInverseHoldsIt()

	/**
	 * The items definition inherits the property's link keys; without a $ref there is none.
	 *
	 * @return void
	 */
	public function testTheItemsDefinitionInheritsThePropertysLinks(): void {
		self::assertSame(
			['type' => 'object', '$ref' => 'note', 'inversedBy' => 'case', 'register' => 4, 'objectConfiguration' => ['handling' => 'cascade']],
			CascadeRules::itemsDefinition(
				property: [
					'type' => 'array',
					'items' => ['type' => 'object'],
					'$ref' => 'note',
					'inversedBy' => 'case',
					'register' => 4,
					'objectConfiguration' => ['handling' => 'cascade'],
					'description' => 'not copied',
				]
			)
		);
		self::assertSame(['$ref' => 'own'], CascadeRules::itemsDefinition(property: ['items' => ['$ref' => 'own'], 'register' => null]));
		self::assertNull(CascadeRules::itemsDefinition(property: ['type' => 'array', 'items' => ['type' => 'string'], 'inversedBy' => 'case']));
	}//end testTheItemsDefinitionInheritsThePropertysLinks()

	/**
	 * A run over the `notes` property.
	 *
	 * @param array<string, mixed> $definition The property definition.
	 * @param array<string, mixed> $stored     The stored object.
	 * @param array<string, mixed> $data       The incoming data.
	 *
	 * @return CascadeRun
	 */
	private function cascadeRun(array $definition, array $stored=[], array $data=[]): CascadeRun {
		$run = new CascadeRun();
		$run->property = 'notes';
		$run->definition = $definition;
		$run->stored = $stored;
		$run->data = $data;
		return $run;
	}//end cascadeRun()
}//end class
