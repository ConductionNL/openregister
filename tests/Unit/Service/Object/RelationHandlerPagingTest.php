<?php

/**
 * Paging of a relation lookup's results.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Service\Object\RelationHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * A limit or offset that is not a number pages with its default instead of failing.
 */
class RelationHandlerPagingTest extends TestCase {

	/**
	 * Requested paging, and the limit and offset used.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: int, 2: int}>
	 */
	public static function paging(): array {
		return [
			'none'              => [[], 30, 0],
			'numeric strings'   => [['_limit' => '2', '_offset' => '1'], 2, 1],
			'integers'          => [['_limit' => 2, '_offset' => 1], 2, 1],
			'not numbers'       => [['_limit' => 'abc', '_offset' => 'x'], 30, 0],
			'arrays'            => [['_limit' => ['1'], '_offset' => ['1']], 30, 0],
			'negative'          => [['_limit' => '-1', '_offset' => -3], 0, 0],
		];
	}//end paging()

	/**
	 * @param array<string, mixed> $query  The paging parameters.
	 * @param int                  $limit  The limit used.
	 * @param int                  $offset The offset used.
	 *
	 * @return void
	 */
	#[DataProvider('paging')]
	public function testPagingParametersAreNumbers(array $query, int $limit, int $offset): void {
		$handler = (new ReflectionClass(RelationHandler::class))->newInstanceWithoutConstructor();
		$paged = (new ReflectionClass(RelationHandler::class))->getMethod('paged');

		$page = $paged->invoke($handler, ['a', 'b', 'c', 'd'], $query);

		self::assertSame(expected: ($query['_limit'] ?? 30), actual: $page['limit']);
		self::assertSame(expected: ($query['_offset'] ?? 0), actual: $page['offset']);
		self::assertSame(expected: 4, actual: $page['total']);
		self::assertSame(expected: array_slice(['a', 'b', 'c', 'd'], $offset, $limit), actual: $page['results']);
	}//end testPagingParametersAreNumbers()
}//end class
