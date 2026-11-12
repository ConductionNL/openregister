<?php

/**
 * The refusal raised when a locked note is written.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Exception;

use OCA\OpenRegister\Exception\NoteLockedException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A locked note answers 423, names the note and keeps the cause.
 *
 * @covers \OCA\OpenRegister\Exception\NoteLockedException
 */
final class NoteLockedExceptionTest extends TestCase {

	/**
	 * The refusal carries 423, the message, the note id and its cause.
	 *
	 * @return void
	 */
	public function testTheRefusalNamesTheLockedNote(): void {
		$cause = new RuntimeException('row locked');
		$refusal = new NoteLockedException(noteId: 41, previous: $cause);

		$this->assertSame(41, $refusal->getNoteId());
		$this->assertSame(423, $refusal->getCode());
		$this->assertSame('This note is locked and can no longer be changed', $refusal->getMessage());
		$this->assertSame($cause, $refusal->getPrevious());
	}//end testTheRefusalNamesTheLockedNote()
}//end class
