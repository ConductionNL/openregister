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

use OCA\OpenRegister\Service\Object\ObjectSourceWindow;
use PHPUnit\Framework\TestCase;

/**
 * ObjectSourceWindow pages a source provider's rows and says where the page sits.
 *
 * @covers \OCA\OpenRegister\Service\Object\ObjectSourceWindow
 */
class ObjectSourceWindowTest extends TestCase {

	/**
	 * A trusted count places a middle page between its neighbours.
	 *
	 * @return void
	 */
	public function testATrustedCountPlacesTheMiddlePage(): void {
		$page = (new ObjectSourceWindow(query: ['limit' => 10, 'offset' => 10]))->page(results: array_fill(0, 10, ['id' => 1]), counted: 35);

		self::assertSame(35, $page['total']);
		self::assertSame(['total' => 35, 'page' => 2, 'pages' => 4, 'limit' => 10, 'next' => 3, 'prev' => 1], $page['@self']);
	}//end testATrustedCountPlacesTheMiddlePage()

	/**
	 * A count smaller than the rows already passed is not trusted: one page of what arrived.
	 *
	 * @return void
	 */
	public function testACountBelowTheRowsSeenIsNotTrusted(): void {
		$page = (new ObjectSourceWindow(query: ['limit' => 10, 'offset' => 20]))->page(results: [['id' => 1], ['id' => 2]], counted: 5);

		self::assertSame(2, $page['total']);
		self::assertSame(['total' => 2, 'page' => 1, 'pages' => 1, 'limit' => 10, 'next' => null, 'prev' => null], $page['@self']);
	}//end testACountBelowTheRowsSeenIsNotTrusted()

	/**
	 * Without a limit the page holds everything, and its limit is the total.
	 *
	 * @return void
	 */
	public function testWithoutALimitTheLimitIsTheTotal(): void {
		$page = (new ObjectSourceWindow(query: ['offset' => -4]))->page(results: [['id' => 1], ['id' => 2], ['id' => 3]], counted: 3);

		self::assertSame(['total' => 3, 'page' => 1, 'pages' => 1, 'limit' => 3, 'next' => null, 'prev' => null], $page['@self']);
	}//end testWithoutALimitTheLimitIsTheTotal()

	/**
	 * An empty source still has one page.
	 *
	 * @return void
	 */
	public function testAnEmptySourceHasOnePage(): void {
		$page = (new ObjectSourceWindow(query: ['limit' => 25]))->page(results: [], counted: 0);

		self::assertSame([], $page['results']);
		self::assertSame(['total' => 0, 'page' => 1, 'pages' => 1, 'limit' => 25, 'next' => null, 'prev' => null], $page['@self']);
	}//end testAnEmptySourceHasOnePage()
}//end class
