<?php

/**
 * Unit tests for CopyObject.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectRelation;
use OCA\OpenRegister\Db\ObjectRelationMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\Object\CopyObject;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The links a copy takes along, each under the caller's rights.
 *
 * @covers \OCA\OpenRegister\Service\Object\CopyObject
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\ObjectRelation
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\Object\PermissionCheckOptions
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\SavePolicy
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\SaveActor
 * @uses   \OCA\OpenRegister\Exception\NotAuthorizedException
 *
 * @spec openspec/changes/records-copy-with-links/specs/objects-crud/spec.md#requirement-an-object-can-be-copied-with-the-links-the-caller-chooses
 */
class CopyObjectTest extends TestCase {

	/**
	 * The relation rows written, as their fields.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $rowsWritten = [];

	/**
	 * The saves made, as [object, uuid, rbac].
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $saves = [];

	/**
	 * The object service.
	 *
	 * @var ObjectOperationsInterface&MockObject
	 */
	private ObjectOperationsInterface&MockObject $objects;

	/**
	 * An object entity.
	 *
	 * @param string               $uuid The uuid.
	 * @param array<string, mixed> $data Its data.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid, array $data = []): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setRegister('1');
		$entity->setSchema('10');
		$entity->setOwner('anna');
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * A relation row of the source.
	 *
	 * @param string               $uuid   The row uuid.
	 * @param array<string, mixed> $fields Its fields.
	 *
	 * @return ObjectRelation
	 */
	private function row(string $uuid, array $fields): ObjectRelation {
		$row = new ObjectRelation();
		$row->hydrate(['uuid' => $uuid, 'sourceUuid' => 'src', 'sourceRegister' => 1, 'sourceSchema' => 10] + $fields);
		return $row;
	}//end row()

	/**
	 * The service.
	 *
	 * @param list<ObjectRelation>           $rows       The source's relation rows.
	 * @param array<string, ObjectEntity>    $readable   The objects the caller may read, by uuid.
	 * @param list<array<string, mixed>>     $usedBy     What `used` answers for the source.
	 * @param list<string>                   $updatable  The objects the caller may update.
	 * @param bool                           $saveFails  Whether a save of a referrer throws.
	 *
	 * @return CopyObject
	 */
	private function service(array $rows = [], array $readable = [], array $usedBy = [], array $updatable = [], bool $saveFails = false): CopyObject {
		$relations = $this->createMock(ObjectRelationMapperInterface::class);
		$relations->method('findBySource')->with('src')->willReturn($rows);
		$relations->method('createFromArray')->willReturnCallback(
			function (array $data): ObjectRelation {
				$this->rowsWritten[] = $data;
				return new ObjectRelation();
			}
		);

		$this->objects = $this->createMock(ObjectOperationsInterface::class);
		$this->objects->method('find')->willReturnCallback(
			static function (int|string $id, mixed $options = null, bool $_rbac = true) use ($readable): ?ObjectEntity {
				if ($_rbac !== true) {
					throw new RuntimeException('links must be read under the caller rights');
				}

				return ($readable[(string) $id] ?? null);
			}
		);
		$this->objects->method('getObjectUsedBy')->willReturnCallback(
			static function (string $objectId, array $query = [], bool $_rbac = true, bool $_multitenancy = true) use ($usedBy): array {
				if ($objectId !== 'src' || $_rbac !== true || $_multitenancy !== true) {
					throw new RuntimeException('used must be read for the source under the caller rights');
				}

				return ['results' => $usedBy, 'total' => count($usedBy)];
			}
		);
		$this->objects->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ObjectSaveOptions $options, bool $_rbac = true) use ($saveFails): ObjectEntity {
				if ($saveFails === true) {
					throw new RuntimeException('disk full');
				}

				$this->saves[] = [$object, $options->uuid, $_rbac];
				return new ObjectEntity();
			}
		);

		$permissions = $this->createMock(PermissionHandlerInterface::class);
		$permissions->method('checkPermission')->willReturnCallback(
			static function (Schema $schema, string $action, mixed $options) use ($updatable): void {
				if ($action !== 'update' || in_array((string) $options->object->getUuid(), $updatable, true) === false) {
					throw new NotAuthorizedException('no');
				}
			}
		);
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturn(new Schema());

		return new CopyObject($relations, $permissions, $schemas, $this->createMock(LoggerInterface::class));
	}//end service()

	/**
	 * Manual rows are re-created with the copy as their source; provenance and prose rows are not.
	 *
	 * @return void
	 */
	public function testRelationRowsAreRecreatedOnTheCopy(): void {
		$rows = [
			$this->row('r1', ['kind' => ObjectRelation::KIND_OBJECT, 'origin' => ObjectRelation::ORIGIN_MANUAL, 'targetUuid' => 'leverancier-1', 'targetTitle' => 'Conduction', 'relationType' => 'supplier']),
			$this->row('r2', ['kind' => ObjectRelation::KIND_EXTERNAL, 'origin' => ObjectRelation::ORIGIN_MANUAL, 'targetUrl' => 'https://example.org/doc', 'targetTitle' => 'Handleiding']),
			$this->row('r3', ['kind' => ObjectRelation::KIND_OBJECT, 'origin' => ObjectRelation::ORIGIN_SPLIT, 'targetUuid' => 'parent']),
			$this->row('r4', ['kind' => ObjectRelation::KIND_OBJECT, 'origin' => ObjectRelation::ORIGIN_PROSE, 'targetUuid' => 'leverancier-1', 'anchor' => 'note-1']),
		];
		$service = $this->service(rows: $rows, readable: ['leverancier-1' => $this->entity('leverancier-1')]);

		$links = $service->links(source: $this->entity('src'), copy: $this->entity('copy'), include: ['relationRows'], objects: $this->objects);

		$this->assertSame(['copied', 'copied', 'skipped', 'skipped'], array_column($links['relationRows'], 'outcome'));
		$this->assertSame(['r1', 'r2', 'r3', 'r4'], array_column($links['relationRows'], 'id'));
		$this->assertSame([], $links['incoming']);
		$this->assertCount(2, $this->rowsWritten);
		$this->assertSame('copy', $this->rowsWritten[0]['sourceUuid']);
		$this->assertSame(1, $this->rowsWritten[0]['sourceRegister']);
		$this->assertSame(10, $this->rowsWritten[0]['sourceSchema']);
		$this->assertSame('leverancier-1', $this->rowsWritten[0]['targetUuid']);
		$this->assertSame('supplier', $this->rowsWritten[0]['relationType']);
		$this->assertSame(ObjectRelation::ORIGIN_MANUAL, $this->rowsWritten[0]['origin']);
		$this->assertArrayNotHasKey('uuid', $this->rowsWritten[0]);
		$this->assertArrayNotHasKey('anchor', $this->rowsWritten[0]);
		$this->assertSame('https://example.org/doc', $this->rowsWritten[1]['targetUrl']);
	}//end testRelationRowsAreRecreatedOnTheCopy()

	/**
	 * A row to an object the caller cannot read is skipped with the reason.
	 *
	 * @return void
	 */
	public function testARowToAnUnreadableObjectIsSkipped(): void {
		$rows    = [$this->row('r1', ['kind' => ObjectRelation::KIND_OBJECT, 'origin' => ObjectRelation::ORIGIN_MANUAL, 'targetUuid' => 'secret'])];
		$service = $this->service(rows: $rows);

		$outcomes = $service->relationRows(source: $this->entity('src'), copy: $this->entity('copy'), objects: $this->objects);

		$this->assertSame([['id' => 'r1', 'title' => 'secret', 'outcome' => 'skipped', 'reason' => 'You may not read the linked object.']], $outcomes);
		$this->assertSame([], $this->rowsWritten);
	}//end testARowToAnUnreadableObjectIsSkipped()

	/**
	 * The copy is added beside the source in an array reference; the source stays.
	 *
	 * @return void
	 */
	public function testTheCopyIsAddedBesideTheSourceInAnArrayReference(): void {
		$suite   = $this->entity('suite', ['naam' => 'Basis', 'applications' => ['other', 'src']]);
		$service = $this->service(
			readable: ['suite' => $suite],
			usedBy: [['naam' => 'Basis', 'applications' => ['other', 'src'], '@self' => ['id' => 'suite', 'name' => 'Basis', 'register' => '1', 'schema' => '10']]],
			updatable: ['suite']
		);

		$outcomes = $service->incoming(source: $this->entity('src'), copy: $this->entity('copy'), objects: $this->objects);

		$this->assertSame([['id' => 'suite', 'title' => 'Basis', 'outcome' => 'copied', 'reason' => null]], $outcomes);
		$this->assertSame([[['id' => 'suite', 'naam' => 'Basis', 'applications' => ['other', 'src', 'copy']], 'suite', true]], $this->saves);
	}//end testTheCopyIsAddedBesideTheSourceInAnArrayReference()

	/**
	 * A referrer the caller may read but not update is reported, not forced.
	 *
	 * @return void
	 */
	public function testAReferrerTheCallerMayNotUpdateIsSkipped(): void {
		$suite   = $this->entity('suite', ['applications' => ['src']]);
		$service = $this->service(
			readable: ['suite' => $suite],
			usedBy: [['applications' => ['src'], '@self' => ['id' => 'suite', 'name' => 'Basis']]]
		);

		$outcomes = $service->incoming(source: $this->entity('src'), copy: $this->entity('copy'), objects: $this->objects);

		$this->assertSame('skipped', $outcomes[0]['outcome']);
		$this->assertSame('You may not update this object.', $outcomes[0]['reason']);
		$this->assertSame([], $this->saves);
	}//end testAReferrerTheCallerMayNotUpdateIsSkipped()

	/**
	 * A single-valued reference is not taken over, and an unreadable referrer is not touched.
	 *
	 * @return void
	 */
	public function testSingleValuedAndUnreadableReferrersAreSkipped(): void {
		$service = $this->service(
			usedBy: [
				['hoofdapplicatie' => 'src', '@self' => ['id' => 'single', 'name' => 'Enkel']],
				['applications' => ['src'], '@self' => ['id' => 'hidden', 'name' => 'Verborgen']],
			],
			updatable: ['single', 'hidden']
		);

		$outcomes = $service->incoming(source: $this->entity('src'), copy: $this->entity('copy'), objects: $this->objects);

		$this->assertSame(
			['Only a single-valued reference points at the source; a copy does not take it over.', 'You may not read this object.'],
			array_column($outcomes, 'reason')
		);
		$this->assertSame([], $this->saves);
	}//end testSingleValuedAndUnreadableReferrersAreSkipped()

	/**
	 * A save that fails is reported per referrer rather than thrown.
	 *
	 * @return void
	 */
	public function testAFailedReferrerSaveIsReported(): void {
		$service = $this->service(
			readable: ['suite' => $this->entity('suite', ['applications' => ['src']])],
			usedBy: [['applications' => ['src'], '@self' => ['id' => 'suite']]],
			updatable: ['suite'],
			saveFails: true
		);

		$outcomes = $service->incoming(source: $this->entity('src'), copy: $this->entity('copy'), objects: $this->objects);

		$this->assertSame('skipped', $outcomes[0]['outcome']);
		$this->assertSame('The reference could not be added: disk full', $outcomes[0]['reason']);
	}//end testAFailedReferrerSaveIsReported()

	/**
	 * Only known link kinds are accepted.
	 *
	 * @return void
	 */
	public function testUnknownKindsAreNamed(): void {
		$this->assertSame([], CopyObject::unknownKinds(include: ['relationRows', 'incoming', 'files']));
		$this->assertSame(['notes', '3'], CopyObject::unknownKinds(include: ['relationRows', 'notes', 3]));
	}//end testUnknownKindsAreNamed()
}//end class
