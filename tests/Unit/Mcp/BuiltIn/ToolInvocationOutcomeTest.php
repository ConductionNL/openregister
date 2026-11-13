<?php

/**
 * The outcome of a built-in tool invocation and the page of a derived
 * search: the object uuid an outcome names, and a page's row offset.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Mcp\BuiltIn
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Mcp\BuiltIn;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\Mcp\BuiltIn\DerivedSearchPage;
use OCA\OpenRegister\Mcp\BuiltIn\ToolInvocationOutcome;
use PHPUnit\Framework\TestCase;

/**
 * ToolInvocationOutcome and DerivedSearchPage.
 *
 * @covers \OCA\OpenRegister\Mcp\BuiltIn\ToolInvocationOutcome
 * @covers \OCA\OpenRegister\Mcp\BuiltIn\DerivedSearchPage
 * @uses \OCA\OpenRegister\Mcp\BuiltIn\ToolInvocationDigest
 */
class ToolInvocationOutcomeTest extends TestCase {

	public function testAnOutcomeNamesTheStringIdOfItsResult(): void {
		$outcome = new ToolInvocationOutcome('d', ['isError' => false], ['id' => 'obj-1', 'title' => 'x']);

		$this->assertSame('obj-1', $outcome->objectUuid());
	}//end testAnOutcomeNamesTheStringIdOfItsResult()

	public function testAFailedOrIdlessOutcomeNamesNoObject(): void {
		$this->assertNull((new ToolInvocationOutcome('d', ['isError' => true], null))->objectUuid());
		$this->assertNull((new ToolInvocationOutcome('d', [], ['id' => 5]))->objectUuid());
	}//end testAFailedOrIdlessOutcomeNamesNoObject()

	public function testTheFirstPageStartsAtZero(): void {
		$this->assertSame(0, (new DerivedSearchPage(page: 1, pageSize: 20))->offset());
	}//end testTheFirstPageStartsAtZero()

	public function testALaterPageSkipsTheEarlierPages(): void {
		$this->assertSame(40, (new DerivedSearchPage(page: 3, pageSize: 20))->offset());
		$this->assertSame(9, (new DerivedSearchPage(page: 4, pageSize: 3))->offset());
	}//end testALaterPageSkipsTheEarlierPages()
}//end class
