<?php

/**
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
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

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Service\Object\ObjectSourceQuery;
use PHPUnit\Framework\TestCase;

/**
 * ObjectSourceQuery turns an object list query into the shape a source provider reads.
 *
 * @covers \OCA\OpenRegister\Service\Object\ObjectSourceQuery
 */
class ObjectSourceQueryTest extends TestCase {

	/**
	 * Underscore paging keys become limit, offset and sort; field keys become filters.
	 *
	 * @return void
	 */
	public function testUnderscoreKeysBecomeTheProviderShape(): void {
		$query = (new ObjectSourceQuery())->forProvider(
			query: ['_limit' => '20', '_page' => 3, '_order' => ['title' => 'asc'], 'status' => 'open', 'year' => 2026, 'tags' => ['a']]
		);

		self::assertSame(20, $query['limit']);
		self::assertSame(40, $query['offset']);
		self::assertSame(['title' => 'asc'], $query['sort']);
		self::assertSame(['status' => 'open', 'year' => 2026], $query['filters']);
	}//end testUnderscoreKeysBecomeTheProviderShape()

	/**
	 * Keys the caller already gave in the provider shape win over the underscore ones.
	 *
	 * @return void
	 */
	public function testProviderKeysWinOverUnderscoreKeys(): void {
		$given = ['limit' => 5, '_limit' => 50, 'offset' => 0, '_offset' => 9, 'sort' => ['a' => 'desc'], '_order' => ['b' => 'asc'], 'filters' => ['x' => 1], 'status' => 'open'];

		self::assertSame($given, (new ObjectSourceQuery())->forProvider(query: $given));
	}//end testProviderKeysWinOverUnderscoreKeys()

	/**
	 * An explicit offset is used as given; a first page or a missing limit gives no offset.
	 *
	 * @return void
	 */
	public function testTheOffsetComesFromTheOffsetOrALaterPage(): void {
		$source = new ObjectSourceQuery();

		self::assertSame(7, $source->forProvider(query: ['_offset' => '7', '_page' => 4, 'limit' => 10])['offset']);
		self::assertArrayNotHasKey('offset', $source->forProvider(query: ['_page' => 1, 'limit' => 10]));
		self::assertArrayNotHasKey('offset', $source->forProvider(query: ['_page' => 3]));
	}//end testTheOffsetComesFromTheOffsetOrALaterPage()

	/**
	 * Reserved, underscore and @ keys, and non-scalar values, are never filters.
	 *
	 * @return void
	 */
	public function testOnlyScalarFieldKeysAreFilters(): void {
		$query = (new ObjectSourceQuery())->forProvider(
			query: ['limit' => 10, 'extend' => 'x', 'fields' => 'y', '_search' => 'q', '@self' => 'z', '' => 'empty', 'nested' => ['a'], 'flag' => true]
		);

		self::assertSame(['flag' => true], $query['filters']);

		self::assertArrayNotHasKey('filters', (new ObjectSourceQuery())->forProvider(query: ['_search' => 'q']));
	}//end testOnlyScalarFieldKeysAreFilters()
}//end class
