<?php

/**
 * The earlier writes of a refused submit are deleted, newest first, and each undo is recorded.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Form;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Form\FormSubmitFailure;
use OCA\OpenRegister\Service\Form\FormWriteCompensation;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Service\Object\ObjectDeletion;

/**
 * Undo order, the permanent delete without RBAC, and a failed undo.
 */
class FormWriteCompensationTest extends TestCase {

	/**
	 * One write as the submit path records it.
	 *
	 * @param string $uuid The stored object's uuid.
	 * @param string $as   The write's name.
	 *
	 * @return array{plan: array<string, mixed>, object: ObjectEntity}
	 */
	private function done(string $uuid, string $as): array {
		$object = new ObjectEntity();
		$object->setUuid($uuid);

		return ['plan' => ['register' => new Register(), 'schema' => new Schema(), 'as' => $as], 'object' => $object];
	}//end done()

	public function testEveryEarlierWriteIsDeletedPermanentlyNewestFirst(): void {
		$deleted = [];
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('deleteObject')->willReturnCallback(
			static function (ObjectDeletion $deletion, bool $_rbac = true, bool $_multitenancy = true, bool $_retentionSweep = false) use (&$deleted): bool {
				$args = [$deletion->uuid, $deletion->target->register, $deletion->target->schema, $_rbac, $_multitenancy, $_retentionSweep, $deletion->currentUser, $deletion->permanent];
				$deleted[] = [$args[0], $args[3], $args[4], $args[7]];
				return true;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(2))->method('warning');

		$compensation = new FormWriteCompensation(objects: $objects, logger: $logger);
		$compensation->compensate([$this->done('u-1', 'a'), $this->done('u-2', 'b')], new FormSubmitFailure('c', new RuntimeException('refused')));

		$this->assertSame([['u-2', false, false, true], ['u-1', false, false, true]], $deleted);
	}//end testEveryEarlierWriteIsDeletedPermanentlyNewestFirst()

	public function testAFailedUndoIsLoggedAndTheOthersStillRun(): void {
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->expects($this->exactly(2))->method('deleteObject')->willReturnCallback(
			static function (ObjectDeletion $deletion, bool $_rbac = true, bool $_multitenancy = true, bool $_retentionSweep = false): bool {
				$args = [$deletion->uuid, $deletion->target->register, $deletion->target->schema, $_rbac, $_multitenancy, $_retentionSweep, $deletion->currentUser, $deletion->permanent];
				if ($args[0] === 'u-2') {
					throw new RuntimeException('gone');
				}

				return true;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error');
		$logger->expects($this->once())->method('warning');

		$compensation = new FormWriteCompensation(objects: $objects, logger: $logger);
		$compensation->compensate([$this->done('u-1', 'a'), $this->done('u-2', 'b')], new FormSubmitFailure('c', new RuntimeException('refused')));
	}//end testAFailedUndoIsLoggedAndTheOthersStillRun()
}//end class
