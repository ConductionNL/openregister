<?php

/**
 * Unit tests for ListConnection
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\GraphQL
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\GraphQL;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\GraphQL\ListConnection;
use OCA\OpenRegister\Service\GraphQL\ListRequest;
use PHPUnit\Framework\TestCase;

/**
 * Pins the GraphQL connection a list result becomes: edges with cursors,
 * page info, total count and facets.
 *
 * @covers \OCA\OpenRegister\Service\GraphQL\ListConnection
 * @uses \OCA\OpenRegister\Service\GraphQL\ListRequest
 * @uses \OCA\OpenRegister\Db\Schema
 */
final class ListConnectionTest extends TestCase {

	/**
	 * A list request with the given arguments.
	 *
	 * @param array $args The GraphQL arguments
	 *
	 * @return ListRequest The request
	 */
	private function request(array $args): ListRequest {
		return new ListRequest(schema: new Schema(), register: null, args: $args);
	}//end request()

	/**
	 * The cursor the connection encodes for a uuid and offset.
	 *
	 * @param string     $uuid   The uuid
	 * @param int|string $offset The offset
	 *
	 * @return string The cursor
	 */
	private function cursor(string $uuid, int|string $offset): string {
		return base64_encode(json_encode(['uuid' => $uuid, 'offset' => $offset]));
	}//end cursor()

	/**
	 * A middle page: cursors count from the result offset, both directions are open.
	 *
	 * @return void
	 */
	public function testAMiddlePage(): void {
		$result     = ['offset' => 10, 'limit' => 2, 'total' => 25, 'facets' => ['f' => 1], 'facetable' => ['g']];
		$connection = new ListConnection(result: $result, request: $this->request(args: []));
		$rows       = [
			['_uuid' => 'u1', '_relevance' => 0.9],
			['@self' => ['uuid' => 'u2']],
		];

		$built = $connection->build(rows: $rows);

		$this->assertSame(
			expected: [
				['cursor' => $this->cursor(uuid: 'u1', offset: 10), 'node' => $rows[0], '_relevance' => 0.9],
				['cursor' => $this->cursor(uuid: 'u2', offset: 11), 'node' => $rows[1], '_relevance' => null],
			],
			actual: $built['edges']
		);
		$this->assertSame(
			expected: [
				'hasNextPage'     => true,
				'hasPreviousPage' => true,
				'startCursor'     => $this->cursor(uuid: 'u1', offset: 10),
				'endCursor'       => $this->cursor(uuid: 'u2', offset: 11),
			],
			actual: $built['pageInfo']
		);
		$this->assertSame(expected: 25, actual: $built['totalCount']);
		$this->assertSame(expected: ['f' => 1], actual: $built['facets']);
		$this->assertSame(expected: ['g'], actual: $built['facetable']);
		$this->assertNull(actual: $built['groups']);
	}//end testAMiddlePage()

	/**
	 * Without offset and limit in the result, the request's own are used; the last page has no next.
	 *
	 * @return void
	 */
	public function testTheRequestSuppliesOffsetAndLimit(): void {
		$connection = new ListConnection(result: ['total' => 3], request: $this->request(args: ['first' => 5]));

		$built = $connection->build(rows: [['_uuid' => 'u1']]);

		$this->assertSame(expected: $this->cursor(uuid: 'u1', offset: 0), actual: $built['edges'][0]['cursor']);
		$this->assertFalse(condition: $built['pageInfo']['hasNextPage']);
		$this->assertFalse(condition: $built['pageInfo']['hasPreviousPage']);
		$this->assertNull(actual: $built['facets']);
	}//end testTheRequestSuppliesOffsetAndLimit()

	/**
	 * A row without a uuid is cursored by its position; an empty page has no cursors and no total.
	 *
	 * @return void
	 */
	public function testRowsWithoutUuidAndEmptyPages(): void {
		$connection = new ListConnection(result: ['offset' => 4], request: $this->request(args: []));

		$this->assertSame(expected: $this->cursor(uuid: '4', offset: 4), actual: $connection->build(rows: [['name' => 'x']])['edges'][0]['cursor']);

		$empty = $connection->build(rows: []);
		$this->assertSame(expected: [], actual: $empty['edges']);
		$this->assertNull(actual: $empty['pageInfo']['startCursor']);
		$this->assertNull(actual: $empty['pageInfo']['endCursor']);
		$this->assertFalse(condition: $empty['pageInfo']['hasNextPage']);
		$this->assertSame(expected: 0, actual: $empty['totalCount']);
	}//end testRowsWithoutUuidAndEmptyPages()
}//end class
