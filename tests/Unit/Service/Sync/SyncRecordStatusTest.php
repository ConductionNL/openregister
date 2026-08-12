<?php

/**
 * Unit tests for the SyncRecordStatus state machine.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Sync
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service\Sync;

use OCA\OpenRegister\Service\Sync\SyncRecordStatus;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Sync\SyncRecordStatus
 */
class SyncRecordStatusTest extends TestCase {

	public function testKnownStatusesAreValid(): void {
		foreach (SyncRecordStatus::all() as $status) {
			$this->assertTrue(SyncRecordStatus::isValid($status));
		}
	}//end testKnownStatusesAreValid()

	public function testUnknownStatusIsInvalid(): void {
		$this->assertFalse(SyncRecordStatus::isValid('nonsense'));
	}//end testUnknownStatusIsInvalid()

	public function testForwardPipelineTransitionsAreAllowed(): void {
		$this->assertTrue(SyncRecordStatus::canTransition(SyncRecordStatus::PENDING, SyncRecordStatus::FETCHED));
		$this->assertTrue(SyncRecordStatus::canTransition(SyncRecordStatus::FETCHED, SyncRecordStatus::IMPORTED));
		$this->assertTrue(SyncRecordStatus::canTransition(SyncRecordStatus::FETCHED, SyncRecordStatus::UNCHANGED));
		$this->assertTrue(SyncRecordStatus::canTransition(SyncRecordStatus::FETCHED, SyncRecordStatus::CONFLICT));
		$this->assertTrue(SyncRecordStatus::canTransition(SyncRecordStatus::PENDING, SyncRecordStatus::FETCH_ERROR));
		$this->assertTrue(SyncRecordStatus::canTransition(SyncRecordStatus::FETCH_ERROR, SyncRecordStatus::FETCHED));
	}//end testForwardPipelineTransitionsAreAllowed()

	public function testIllegalTransitionsAreRejected(): void {
		// Cannot skip Fetch.
		$this->assertFalse(SyncRecordStatus::canTransition(SyncRecordStatus::PENDING, SyncRecordStatus::IMPORTED));
		// Cannot move backwards from a terminal state.
		$this->assertFalse(SyncRecordStatus::canTransition(SyncRecordStatus::IMPORTED, SyncRecordStatus::FETCHED));
		// Cannot resurrect a permanent error.
		$this->assertFalse(SyncRecordStatus::canTransition(SyncRecordStatus::PERMANENT_ERROR, SyncRecordStatus::IMPORTED));
		// Unknown endpoints reject.
		$this->assertFalse(SyncRecordStatus::canTransition(SyncRecordStatus::PENDING, 'bogus'));
	}//end testIllegalTransitionsAreRejected()

	public function testTerminalDetection(): void {
		$this->assertTrue(SyncRecordStatus::isTerminal(SyncRecordStatus::IMPORTED));
		$this->assertTrue(SyncRecordStatus::isTerminal(SyncRecordStatus::UNCHANGED));
		$this->assertTrue(SyncRecordStatus::isTerminal(SyncRecordStatus::PERMANENT_ERROR));
		$this->assertFalse(SyncRecordStatus::isTerminal(SyncRecordStatus::PENDING));
		$this->assertFalse(SyncRecordStatus::isTerminal(SyncRecordStatus::FETCHED));
	}//end testTerminalDetection()

	public function testErrorDetection(): void {
		$this->assertTrue(SyncRecordStatus::isError(SyncRecordStatus::FETCH_ERROR));
		$this->assertTrue(SyncRecordStatus::isError(SyncRecordStatus::IMPORT_ERROR));
		$this->assertTrue(SyncRecordStatus::isError(SyncRecordStatus::PERMANENT_ERROR));
		$this->assertFalse(SyncRecordStatus::isError(SyncRecordStatus::IMPORTED));
		$this->assertFalse(SyncRecordStatus::isError(SyncRecordStatus::CONFLICT));
	}//end testErrorDetection()

	public function testConflictCanResolveToImportedOrUnchanged(): void {
		$this->assertTrue(SyncRecordStatus::canTransition(SyncRecordStatus::CONFLICT, SyncRecordStatus::IMPORTED));
		$this->assertTrue(SyncRecordStatus::canTransition(SyncRecordStatus::CONFLICT, SyncRecordStatus::UNCHANGED));
	}//end testConflictCanResolveToImportedOrUnchanged()
}//end class
