<?php

/**
 * Normalising a declared approving set.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Task
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Task;

use OCA\OpenRegister\Service\Task\ApprovingOutcomeSet;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Task\ApprovingOutcomeSet
 */
class ApprovingOutcomeSetTest extends TestCase {

	public function testEntriesAreTrimmedAndLowercased(): void {
		self::assertSame(['approved', 'signed'], (new ApprovingOutcomeSet(declared: [' Approved ', 'SIGNED']))->entries());
	}//end testEntriesAreTrimmedAndLowercased()

	public function testEmptyAndNonStringEntriesAreDropped(): void {
		self::assertSame(['done'], (new ApprovingOutcomeSet(declared: ['', '  ', 7, null, 'done']))->entries());
	}//end testEmptyAndNonStringEntriesAreDropped()

	public function testANonListDeclarationHasNoEntries(): void {
		self::assertSame([], (new ApprovingOutcomeSet(declared: 'approved'))->entries());
		self::assertSame([], (new ApprovingOutcomeSet(declared: null))->entries());
	}//end testANonListDeclarationHasNoEntries()
}//end class
