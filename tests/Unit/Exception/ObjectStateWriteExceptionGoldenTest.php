<?php

/**
 * Golden test: the archived and frozen refusals keep their sentence and body.
 *
 * Pins the message, the state fields and the response body of both refusals
 * for a fully stamped marker, an empty marker and a marker holding non-string
 * or empty values, so moving the marker reading into a collaborator cannot
 * change what a refused writer is told.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Exception
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Exception;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\ObjectStateWriteException;
use PHPUnit\Framework\TestCase;

/**
 * The archived and frozen refusals, byte for byte.
 *
 * @covers \OCA\OpenRegister\Exception\ObjectStateWriteException
 */
class ObjectStateWriteExceptionGoldenTest extends TestCase {
	/**
	 * A fully stamped archive marker names actor, date and reason.
	 *
	 * @return void
	 */
	public function testArchivedWithAFullMarker(): void {
		$object = new ObjectEntity();
		$object->setArchived(['by' => 'anna', 'at' => '2026-01-02T03:04:05+00:00', 'reason' => 'closed']);

		$refusal = ObjectStateWriteException::archived($object);

		$this->assertSame(
			'Cannot write to this object: it was archived by anna on 2026-01-02T03:04:05+00:00. Restore it from the archive before changing it.',
			$refusal->getMessage()
		);
		$this->assertSame(409, $refusal->getCode());
		$this->assertSame(
			['error' => $refusal->getMessage(), 'state' => 'archived', 'by' => 'anna', 'at' => '2026-01-02T03:04:05+00:00', 'reason' => 'closed'],
			$refusal->toResponseBody()
		);
		$this->assertSame('archived', $refusal->getState());
		$this->assertSame('anna', $refusal->getActor());
		$this->assertSame('2026-01-02T03:04:05+00:00', $refusal->getAt());
		$this->assertSame('closed', $refusal->getReason());
	}//end testArchivedWithAFullMarker()

	/**
	 * No marker at all falls back to the unknown-user sentence.
	 *
	 * @return void
	 */
	public function testArchivedWithoutAMarker(): void {
		$refusal = ObjectStateWriteException::archived(new ObjectEntity());

		$this->assertSame(
			'Cannot write to this object: it was archived by an unknown user on an unrecorded date. Restore it from the archive before changing it.',
			$refusal->getMessage()
		);
		$this->assertSame(
			['error' => $refusal->getMessage(), 'state' => 'archived', 'by' => null, 'at' => null, 'reason' => null],
			$refusal->toResponseBody()
		);
	}//end testArchivedWithoutAMarker()

	/**
	 * Empty strings and non-strings in a marker read as absent.
	 *
	 * @return void
	 */
	public function testFrozenTreatsEmptyAndNonStringValuesAsAbsent(): void {
		$object = new ObjectEntity();
		$object->setFrozen(['by' => '', 'at' => 12, 'state' => '', 'reason' => ['x']]);

		$refusal = ObjectStateWriteException::frozen($object);

		$this->assertSame(
			'Cannot write to this object: it was frozen by an unknown user on an unrecorded date. Unfreeze it before changing it.',
			$refusal->getMessage()
		);
		$this->assertSame(
			['error' => $refusal->getMessage(), 'state' => 'frozen', 'by' => null, 'at' => null, 'reason' => null],
			$refusal->toResponseBody()
		);
	}//end testFrozenTreatsEmptyAndNonStringValuesAsAbsent()

	/**
	 * A frozen marker with a state names the state it was frozen on entering.
	 *
	 * @return void
	 */
	public function testFrozenWithAFullMarker(): void {
		$object = new ObjectEntity();
		$object->setFrozen(['by' => 'bob', 'at' => '2026-05-06', 'state' => 'besloten', 'reason' => 'decided']);

		$refusal = ObjectStateWriteException::frozen($object);

		$this->assertSame(
			'Cannot write to this object: it was frozen by bob on 2026-05-06 on entering the state "besloten". Unfreeze it before changing it.',
			$refusal->getMessage()
		);
		$this->assertSame(
			['error' => $refusal->getMessage(), 'state' => 'frozen', 'by' => 'bob', 'at' => '2026-05-06', 'reason' => 'decided'],
			$refusal->toResponseBody()
		);
	}//end testFrozenWithAFullMarker()

	/**
	 * The plain constructor keeps its default sentence, code and empty fields.
	 *
	 * @return void
	 */
	public function testThePlainConstructorKeepsItsDefaults(): void {
		$refusal = new ObjectStateWriteException();

		$this->assertSame('Cannot write to this object in its current state', $refusal->getMessage());
		$this->assertSame(409, $refusal->getCode());
		$this->assertSame(409, ObjectStateWriteException::HTTP_STATUS);
		$this->assertNull($refusal->getState());
		$this->assertNull($refusal->getActor());
	}//end testThePlainConstructorKeepsItsDefaults()
}//end class
