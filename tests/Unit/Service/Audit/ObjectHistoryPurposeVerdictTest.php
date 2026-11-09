<?php

/**
 * The reader path runs the doelbinding guard once per register and schema a request.
 *
 * The guard writes a refusal entry each time it refuses, so a list that
 * walks many objects of one purpose-bound schema would write one entry per
 * object. The verdict is kept per register and schema and replayed.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Audit
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-is-readable-within-a-callers-own-scope
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Audit;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Audit\HistoryEntryRedactor;
use OCA\OpenRegister\Service\Audit\ObjectHistoryForReaders;
use OCA\OpenRegister\Service\Audit\ObjectHistoryServices;
use OCA\OpenRegister\Service\Audit\PurposeGuard;
use OCA\OpenRegister\Service\Audit\PurposeRefusedException;
use OCA\OpenRegister\Service\Audit\ReadEventVisibility;
use OCA\OpenRegister\Service\Object\ObjectReadAccess;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Audit\ObjectHistoryForReaders
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Service\Audit\PurposeRefusedException
 */
final class ObjectHistoryPurposeVerdictTest extends TestCase {

	/**
	 * The schemas by id: 8 is purpose-bound, 7 and 9 are not.
	 *
	 * @param int $schemaId The schema id.
	 *
	 * @return Schema The schema.
	 */
	private function schema(int $schemaId): Schema {
		$schema = new Schema();
		$schema->setId($schemaId);

		return $schema;
	}//end schema()

	/**
	 * An object of a schema.
	 *
	 * @param string $uuid     Its uuid.
	 * @param int    $schemaId Its schema.
	 *
	 * @return ObjectEntity The object.
	 */
	private function object(string $uuid, int $schemaId): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setRegister('5');
		$object->setSchema((string)$schemaId);

		return $object;
	}//end object()

	/**
	 * The reader path over every given object as readable, with the given guard.
	 *
	 * @param array<ObjectEntity>     $objects The objects.
	 * @param PurposeGuard&MockObject $guard   The guard.
	 *
	 * @return ObjectHistoryForReaders The reader path.
	 */
	private function history(array $objects, PurposeGuard&MockObject $guard): ObjectHistoryForReaders {
		$byUuid = [];
		foreach ($objects as $object) {
			$byUuid[$object->getUuid()] = $object;
		}

		$access = $this->createMock(ObjectReadAccess::class);
		$access->method('readable')->willReturnCallback(
			static fn (string $register, string $schema, string $id): ?ObjectEntity => ($byUuid[$id] ?? null)
		);
		$access->method('schemaOf')->willReturnCallback(
			fn (ObjectEntity $object): Schema => $this->schema(schemaId: (int)$object->getSchema())
		);

		$register = new Register();
		$register->setId(5);
		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willReturn($register);

		$redactor = $this->createMock(HistoryEntryRedactor::class);
		$redactor->method('redact')->willReturn([]);

		return new ObjectHistoryForReaders(
			$access,
			new ObjectHistoryServices(
				$this->createMock(AuditTrailMapper::class),
				$registers
			),
			$redactor,
			$guard,
			$this->createMock(ReadEventVisibility::class)
		);
	}//end history()

	public function testARefusalIsDecidedOnceAndGivenForEveryObjectOfThatSchema(): void {
		$objects = [$this->object('a', 8), $this->object('b', 8), $this->object('c', 8)];
		$guard = $this->createMock(PurposeGuard::class);
		$guard->expects($this->once())->method('enforce')
			->willThrowException(new PurposeRefusedException(rule: 'purpose-missing', reason: 'Name a purpose.'));

		$history = $this->history(objects: $objects, guard: $guard);

		$refused = 0;
		foreach ($objects as $object) {
			try {
				$history->readerEntries(located: $object, rows: []);
			} catch (PurposeRefusedException $refusal) {
				$refused++;
			}
		}

		self::assertSame(3, $refused);
	}//end testARefusalIsDecidedOnceAndGivenForEveryObjectOfThatSchema()

	public function testEachSchemaIsDecidedOnItsOwn(): void {
		$objects = [$this->object('a', 7), $this->object('b', 8), $this->object('c', 7), $this->object('d', 8)];
		$guard = $this->createMock(PurposeGuard::class);
		$asked = [];
		$guard->expects($this->exactly(2))->method('enforce')->willReturnCallback(
			static function (?Register $register, ?Schema $schema) use (&$asked): ?string {
				$asked[] = $schema?->getId();
				if ($schema?->getId() === 8) {
					throw new PurposeRefusedException(rule: 'purpose-missing', reason: 'Name a purpose.');
				}

				return null;
			}
		);

		$history = $this->history(objects: $objects, guard: $guard);

		$answers = [];
		foreach ($objects as $object) {
			try {
				$answers[] = $history->readerEntries(located: $object, rows: []);
			} catch (PurposeRefusedException $refusal) {
				$answers[] = 'refused';
			}
		}

		self::assertSame([7, 8], $asked);
		self::assertSame([[], 'refused', [], 'refused'], $answers);
	}//end testEachSchemaIsDecidedOnItsOwn()
}//end class
