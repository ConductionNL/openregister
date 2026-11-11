<?php

/**
 * RecentLensOrder orders and stamps a result page that also holds non-object rows.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper\RecentLensOrder;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\RecentLensOrder
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 */
class RecentLensOrderMixedResultsTest extends TestCase {

	/**
	 * An object with the given uuid.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return ObjectEntity
	 */
	private static function object(string $uuid): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		return $object;
	}//end object()

	/**
	 * A row that is not an object sorts as uuid '' among the unviewed, ahead of unviewed objects.
	 *
	 * @return void
	 */
	public function testANonObjectRowSortsAsAnUnviewedEmptyUuid(): void {
		$viewedLast = self::object('b');
		$viewedFirst = self::object('a');
		$unviewed = self::object('z');
		$row = ['not' => 'an object'];

		$sorted = RecentLensOrder::sort([$unviewed, $row, $viewedLast, $viewedFirst], ['a' => '2026-01-02T00:00:00+00:00', 'b' => '2026-01-01T00:00:00+00:00']);

		$this->assertSame([$viewedFirst, $viewedLast, $row, $unviewed], $sorted);
	}//end testANonObjectRowSortsAsAnUnviewedEmptyUuid()

	/**
	 * Stamping skips rows that are not objects and objects the history does not know.
	 *
	 * @return void
	 */
	public function testStampSkipsNonObjectsAndUnknownObjects(): void {
		$known = self::object('a');
		$unknown = self::object('z');

		RecentLensOrder::stamp([['uuid' => 'a'], $known, $unknown], ['a' => '2026-01-02T00:00:00+00:00']);

		$this->assertSame('2026-01-02T00:00:00+00:00', $known->jsonSerialize()['@self']['viewedAt'] ?? null);
		$this->assertArrayNotHasKey('viewedAt', $unknown->jsonSerialize()['@self']);
	}//end testStampSkipsNonObjectsAndUnknownObjects()
}//end class
