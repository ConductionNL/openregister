<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * MagicMapper::deleteObjects asks the delete guards before it writes.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db
 * @license  EUPL-1.2
 * @link     https://github.com/ConductionNL/openregister
 *
 * @spec openspec/changes/archival-frozen-refuses-delete-and-dates-follow/specs/retention-management/spec.md#requirement-a-frozen-record-cannot-be-deleted
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\HookStoppedException;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Exception\HookStoppedException
 */
class MagicMapperDeleteObjectsGuardTest extends TestCase {

	/**
	 * A mapper finding every uuid but `gone`, and refusing `kept`.
	 *
	 * @return MagicMapper
	 */
	private function mapper(): MagicMapper {
		$register = new Register();
		$register->setId(1);
		$schema = new Schema();
		$schema->setId(2);

		$mapper = $this->createPartialMock(
			MagicMapper::class,
			['findAcrossAllSources', 'assertDeletable', 'deleteObjectsByUuids']
		);
		$mapper->method('findAcrossAllSources')->willReturnCallback(
			static function (string $identifier) use ($register, $schema): array {
				if ($identifier === 'gone') {
					throw new DoesNotExistException('gone');
				}

				$object = new ObjectEntity();
				$object->setUuid($identifier);

				return ['object' => $object, 'register' => $register, 'schema' => $schema];
			}
		);
		$mapper->method('assertDeletable')->willReturnCallback(
			static function (ObjectEntity $entity): void {
				if ($entity->getUuid() === 'kept') {
					throw new HookStoppedException(message: 'kept');
				}
			}
		);

		return $mapper;
	}//end mapper()

	public function testARefusedRowStopsTheDeleteBeforeAnyRowIsWritten(): void {
		$mapper = $this->mapper();
		$mapper->expects($this->never())->method('deleteObjectsByUuids');

		$this->expectException(HookStoppedException::class);

		$mapper->deleteObjects(uuids: ['free', 'kept']);
	}//end testARefusedRowStopsTheDeleteBeforeAnyRowIsWritten()

	public function testAMissingRowIsStillSkipped(): void {
		$mapper = $this->mapper();
		$mapper->expects($this->once())->method('deleteObjectsByUuids')->willReturn(1);

		$results = $mapper->deleteObjects(uuids: ['gone', 'free']);

		$this->assertSame([['count' => 1, 'uuids' => ['free']]], $results);
	}//end testAMissingRowIsStillSkipped()
}//end class
