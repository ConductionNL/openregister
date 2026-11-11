<?php

/**
 * RequestListPage tests.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller\Support
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\Support\RequestListPage;
use PHPUnit\Framework\TestCase;

final class RequestListPageTest extends TestCase {
	/**
	 * The limit and offset for each shape of list request.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: int|null, 2: int|null}>
	 */
	public static function requests(): array {
		return [
			'nothing' => [[], null, null],
			'limit only' => [['_limit' => '20'], 20, null],
			'offset only' => [['_offset' => '5'], null, 5],
			'page without limit keeps the offset' => [['_page' => '3', '_offset' => '7'], null, 7],
			'page with limit wins over offset' => [['_limit' => '10', '_page' => '3', '_offset' => '7'], 10, 20],
			'first page' => [['_limit' => 10, '_page' => 1], 10, 0],
		];
	}//end requests()

	/**
	 * @dataProvider requests
	 *
	 * @param array<string, mixed> $params The request parameters.
	 * @param int|null             $limit  The expected limit.
	 * @param int|null             $offset The expected offset.
	 *
	 * @return void
	 */
	public function testReadsLimitAndOffset(array $params, ?int $limit, ?int $offset): void {
		$page = new RequestListPage(params: $params);

		self::assertSame($limit, $page->limit());
		self::assertSame($offset, $page->offset());
	}//end testReadsLimitAndOffset()
}//end class
