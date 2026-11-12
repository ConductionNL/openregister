<?php

/**
 * Characterisation of FileExtractionListing::sortRows(): rows sorted by the requested column
 * (risk levels by their rank), ascending or descending, missing values as zero, ties kept in
 * their order.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Files\Controller\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Files\Controller\Support;

use OCA\OpenRegister\Files\Controller\Support\FileExtractionListing;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Golden in-PHP sort of the extraction listing.
 *
 * @covers \OCA\OpenRegister\Files\Controller\Support\FileExtractionListing
 */
final class FileExtractionListingSortGoldenTest extends TestCase {

	/**
	 * Rows with an id, an entity count and a risk level.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function rows(): array {
		return [
			['id' => 1, 'entityCount' => 5, 'riskLevel' => 'low'],
			['id' => 2, 'entityCount' => 9, 'riskLevel' => 'very_high'],
			['id' => 3, 'riskLevel' => 'unknown'],
			['id' => 4, 'entityCount' => 5, 'riskLevel' => 'none'],
			['id' => 5, 'entityCount' => 1, 'riskLevel' => 'high'],
			['id' => 6, 'entityCount' => 2, 'riskLevel' => 'medium'],
		];
	}//end rows()

	/**
	 * The sort and order, and the ids they give.
	 *
	 * @return array<string, array{0: string, 1: string, 2: list<int>}>
	 */
	public static function cases(): array {
		return [
			'entity count ascending' => ['entityCount', 'ASC', [3, 5, 6, 1, 4, 2]],
			'entity count descending' => ['entityCount', 'DESC', [2, 1, 4, 6, 5, 3]],
			'risk ascending' => ['riskLevel', 'ASC', [3, 4, 1, 6, 5, 2]],
			'risk descending' => ['riskLevel', 'DESC', [2, 5, 6, 1, 3, 4]],
			'any other order is descending' => ['entityCount', 'asc', [2, 1, 4, 6, 5, 3]],
			'a missing column sorts nothing' => ['extractedAt', 'ASC', [1, 2, 3, 4, 5, 6]],
		];
	}//end cases()

	/**
	 * Rows come back in the expected order.
	 *
	 * @param string    $sort     The sort column.
	 * @param string    $order    The order.
	 * @param list<int> $expected The ids, in order.
	 *
	 * @return void
	 *
	 * @dataProvider cases
	 */
	public function testSortRows(string $sort, string $order, array $expected): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default=null): mixed => ['sort' => $sort, 'order' => $order][$key] ?? $default
		);

		$sorted = (new FileExtractionListing($request))->sortRows(self::rows());

		$this->assertSame($expected, array_column($sorted, 'id'));
	}//end testSortRows()
}//end class
