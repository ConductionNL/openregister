<?php

/**
 * Characterisation of the cross-table fallback of a related-object set.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Object\RelatedObjectSet;
use PHPUnit\Framework\TestCase;

/**
 * withFallback: only missing rows are added, and only those the filter admits.
 *
 * @covers \OCA\OpenRegister\Service\Object\RelatedObjectSet
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 */
class RelatedObjectSetFallbackTest extends TestCase {

	/**
	 * An object with a uuid.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return ObjectEntity The object.
	 */
	private function object(string $uuid): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		return $object;
	}//end object()

	/**
	 * Found rows stay first; located rows that are still missing follow, in located order.
	 *
	 * @return void
	 */
	public function testWithoutAFilterEveryMissingRowIsAdded(): void {
		$set = new RelatedObjectSet(['a', 'b', 'c']);
		$set->offer($this->object('a'));

		$result = $set->withFallback([$this->object('c'), $this->object('a'), $this->object('x'), $this->object('b')]);

		$this->assertSame(['a', 'c', 'b'], array_map(static fn (ObjectEntity $o): ?string => $o->getUuid(), $result));
	}//end testWithoutAFilterEveryMissingRowIsAdded()

	/**
	 * The filter is asked about missing rows only, in located order, and decides which are kept.
	 *
	 * @return void
	 */
	public function testTheFilterIsAskedAboutMissingRowsOnly(): void {
		$set = new RelatedObjectSet(['a', 'b', 'c']);
		$set->offer($this->object('a'));
		$asked = [];

		$result = $set->withFallback(
			[$this->object('a'), $this->object('c'), $this->object('b'), $this->object('z')],
			static function (ObjectEntity $o) use (&$asked): bool {
				$asked[] = $o->getUuid();
				return $o->getUuid() === 'b';
			}
		);

		$this->assertSame(['c', 'b'], $asked);
		$this->assertSame(['a', 'b'], array_map(static fn (ObjectEntity $o): ?string => $o->getUuid(), $result));
	}//end testTheFilterIsAskedAboutMissingRowsOnly()
}//end class
