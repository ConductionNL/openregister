<?php

/**
 * A chunk of relationships loads the related objects the caller may read.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/linked-entity-types/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\RelationHandler;
use OCA\OpenRegister\Service\Object\RelationHandlerStores;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * loadRelationshipChunkOptimized() and bulkLoadRelationshipsBatched() against the cross-table lookup.
 */
class RelationHandlerChunkLoadTest extends TestCase {

	/**
	 * A handler whose tables hold `ours-1` (the caller's organisation) and `theirs-1` (another).
	 *
	 * @param bool $readable What the row-level RBAC answers for every row.
	 *
	 * @return RelationHandler The handler.
	 */
	private function handler(bool $readable = true): RelationHandler {
		$ours   = $this->object(uuid: 'ours-1', organisation: 'org-a');
		$theirs = $this->object(uuid: 'theirs-1', organisation: 'org-b');

		$magic = $this->createMock(originalClassName: MagicMapper::class);
		$magic->method('findMultipleAcrossAllMagicTables')->willReturnCallback(
			static fn (array $uuids): array => array_values(array_filter([$ours, $theirs], static fn (ObjectEntity $o): bool => in_array($o->getUuid(), $uuids, true)))
		);
		// The per-table query and the single-object read apply the organisation filter.
		$magic->method('findAllInRegisterSchemaTable')->willReturn([$ours]);
		$magic->method('find')->willReturnCallback(
			static fn (string|int $identifier): ObjectEntity => match ($identifier) {
				'ours-1' => $ours,
				default => throw new DoesNotExistException('not found'),
			}
		);

		$registerMapper = $this->createMock(originalClassName: RegisterMapper::class);
		$registerMapper->method('find')->willReturnCallback(
			static function ($id): Register {
				$register = new Register();
				$register->setId((int)$id);
				return $register;
			}
		);
		$schemaMapper = $this->createMock(originalClassName: SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(
			static function ($id): Schema {
				$schema = new Schema();
				$schema->setId((int)$id);
				return $schema;
			}
		);

		$rbac = $this->createMock(originalClassName: MagicRbacHandler::class);
		$rbac->method('isAdmin')->willReturn(false);
		$rbac->method('getCurrentUserId')->willReturn('bob');
		$rbac->method('hasRowPermission')->willReturn($readable);

		return new RelationHandler(
			stores: new RelationHandlerStores(objects: $magic, schemas: $schemaMapper, registers: $registerMapper),
			rbacHandler: $rbac,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);
	}//end handler()

	/**
	 * An object in register 1, schema 3.
	 *
	 * @param string $uuid         The uuid.
	 * @param string $organisation The owning organisation.
	 *
	 * @return ObjectEntity The object.
	 */
	private function object(string $uuid, string $organisation): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setRegister('1');
		$object->setSchema('3');
		$object->setOrganisation($organisation);
		$object->setOwner('alice');
		return $object;
	}//end object()

	/**
	 * The uuids of the objects.
	 *
	 * @param ObjectEntity[] $objects The objects.
	 *
	 * @return string[]
	 */
	private static function uuids(array $objects): array {
		return array_values(array_unique(array_map(static fn (ObjectEntity $o): string => (string)$o->getUuid(), $objects)));
	}//end uuids()

	/**
	 * The chunk holds the related object the caller may read, and not another organisation's.
	 *
	 * @return void
	 */
	public function testChunkLoadsTheReadableRelatedObjects(): void {
		$this->assertSame(['ours-1'], self::uuids($this->handler()->loadRelationshipChunkOptimized(['ours-1', 'theirs-1'])));
	}//end testChunkLoadsTheReadableRelatedObjects()

	/**
	 * A related object the row-level RBAC refuses is not loaded.
	 *
	 * @return void
	 */
	public function testChunkLeavesOutRowsTheCallerMayNotRead(): void {
		$this->assertSame([], $this->handler(readable: false)->loadRelationshipChunkOptimized(['ours-1', 'theirs-1']));
	}//end testChunkLeavesOutRowsTheCallerMayNotRead()

	/**
	 * The batched load indexes what the chunks load by uuid.
	 *
	 * @return void
	 */
	public function testBulkLoadIndexesTheLoadedObjects(): void {
		$loaded = $this->handler()->bulkLoadRelationshipsBatched(['ours-1', 'theirs-1']);

		$this->assertArrayHasKey('ours-1', $loaded);
		$this->assertArrayNotHasKey('theirs-1', $loaded);
	}//end testBulkLoadIndexesTheLoadedObjects()
}//end class
