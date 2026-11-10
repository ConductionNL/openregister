<?php
/**
 * SPDX-FileCopyrightText: 2024 Conduction b.v. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Row-reader stubs for an IResult double, across every declared NC major.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Support
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl
 * @link     https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Support;

use OCP\DB\IResult;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Stubs both spellings of the IResult row readers, where they exist.
 *
 * QBMapper reads rows by a different name per server major: up to NC 34
 * `findOneQuery()`/`findEntities()` call `fetch()`/`fetchAll()`, and from NC 35
 * they call `fetchAssociative()`/`fetchAllAssociative()`. A double that stubs
 * only the older pair makes every mapper test on NC 35 read an EMPTY result
 * from a builder that has rows.
 *
 * The newer pair cannot simply be stubbed unconditionally, which is the part
 * that is easy to get wrong: `fetchAssociative()` was added to OCP\DB\IResult in
 * NC 33, and this app declares min-version 32. On a real NC 32 server PHPUnit
 * refuses to configure it —
 *
 *   MethodCannotBeConfiguredException: Trying to configure method
 *   "fetchAssociative" which cannot be configured because it does not exist
 *
 * — so the floor goes red instead of the ceiling. `nextcloud/ocp` ^34 DOES
 * declare it, and its docblock says "Since 33.0.0, prefer using
 * fetchAssociative", so neither the installed stubs nor that note tell you about
 * NC 32; only the `nextcloud:32-apache` job does.
 *
 * Hence `method_exists()` on the interface: present on 33, 34 and 35, absent on
 * 32, and the older pair carries 32 on its own.
 */
trait ResultRowReaderTrait {
	/**
	 * Stub the single-row readers this server actually declares.
	 *
	 * @param MockObject $result The IResult double.
	 * @param callable   $next   Returns the next row, or false when drained.
	 *                           Bound to BOTH names so one cursor is shared —
	 *                           binding separately gives each name its own
	 *                           position, and a uniqueness guard fetch then
	 *                           hands back the same row twice.
	 *
	 * @return void
	 */
	private function stubRowReader(MockObject $result, callable $next): void {
		$result->method('fetch')->willReturnCallback($next);

		if (method_exists(IResult::class, 'fetchAssociative') === true) {
			$result->method('fetchAssociative')->willReturnCallback($next);
		}
	}//end stubRowReader()

	/**
	 * Stub the all-rows readers this server actually declares.
	 *
	 * @param MockObject         $result The IResult double.
	 * @param array<int, mixed>  $rows   Every row the result holds.
	 *
	 * @return void
	 */
	private function stubAllRowsReader(MockObject $result, array $rows): void {
		$result->method('fetchAll')->willReturn($rows);

		if (method_exists(IResult::class, 'fetchAllAssociative') === true) {
			$result->method('fetchAllAssociative')->willReturn($rows);
		}
	}//end stubAllRowsReader()
}//end trait
