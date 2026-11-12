<?php

/**
 * Characterisation tests for ActivityRowFilter::admits.
 *
 * Each of the three filters (type, actor, after) is pinned unset, matching and
 * refusing, so the predicate can be split without changing which rows pass.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration\Providers
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration\Providers;

use OCA\OpenRegister\Service\Integration\Providers\ActivityRowFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins which activity rows pass the type, actor and after filters.
 */
final class ActivityRowFilterAdmitsTest extends TestCase {

	/**
	 * @param array<string, mixed> $row The activity row.
	 */
	#[DataProvider('rows')]
	public function testAdmits(ActivityRowFilter $filter, array $row, bool $expected): void {
		$this->assertSame($expected, $filter->admits($row));
	}//end testAdmits()

	/**
	 * @return array<string, array{0: ActivityRowFilter, 1: array<string, mixed>, 2: bool}>
	 */
	public static function rows(): array {
		$row = ['type' => 'file_created', 'affecteduser' => 'alice', 'timestamp' => 1000];
		return [
			'no filters' => [new ActivityRowFilter('', '', 0), $row, true],
			'no filters, empty row' => [new ActivityRowFilter('', '', 0), [], true],
			'type matches' => [new ActivityRowFilter('file_created', '', 0), $row, true],
			'type differs' => [new ActivityRowFilter('file_deleted', '', 0), $row, false],
			'type filter, row without type' => [new ActivityRowFilter('file_created', '', 0), [], false],
			'actor matches' => [new ActivityRowFilter('', 'alice', 0), $row, true],
			'actor differs' => [new ActivityRowFilter('', 'bob', 0), $row, false],
			'after met exactly' => [new ActivityRowFilter('', '', 1000), $row, true],
			'after not met' => [new ActivityRowFilter('', '', 1001), $row, false],
			'negative after is no filter' => [new ActivityRowFilter('', '', -5), [], true],
			'after filter, row without timestamp' => [new ActivityRowFilter('', '', 1), [], false],
			'all match' => [new ActivityRowFilter('file_created', 'alice', 999), $row, true],
			'type matches, actor refuses' => [new ActivityRowFilter('file_created', 'bob', 0), $row, false],
			'type and actor match, after refuses' => [new ActivityRowFilter('file_created', 'alice', 2000), $row, false],
			'numeric type compared as string' => [new ActivityRowFilter('7', '', 0), ['type' => 7], true],
		];
	}//end rows()
}//end class
