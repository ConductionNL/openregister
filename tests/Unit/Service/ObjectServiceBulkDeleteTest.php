<?php

/**
 * Unit coverage for ObjectService::deleteObjects(), the engine behind
 * `POST /api/bulk/{register}/{schema}/delete`.
 *
 * Two properties are pinned here, and both were broken.
 *
 * ACCOUNTING. The endpoint reports `requested_count`, `deleted_count` and
 * `skipped_count`, and a caller reads them to learn what happened to each row it
 * submitted. Those three only mean anything if every requested row lands in exactly
 * one of the two outcome buckets. It did not: UUIDs the permission filter removed
 * were dropped without a trace, and so was any UUID whose delete handler answered
 * `false` rather than throwing — the loop's `if ($result === true)` had no else. A
 * live rig answered `requested 1, deleted 0, skipped 0, success: true` with the row
 * still in place, which is the worst possible answer: it names no failure to act on.
 *
 * ARCHIVAL IMMUTABILITY. openregister#3428 made `Schema::hasArchivalAnnotation()`
 * the single definition of the rule and routed `ObjectService::deleteObject()` and
 * the purge routes through it. The bulk loop calls `DeleteObject::deleteObject()`
 * directly, so it never passed that gate — a third door onto the same destruction.
 *
 * These tests run the real `deleteObjects()` over doubled collaborators rather than
 * reimplementing its conditions: the archival decision is taken by the shipped
 * predicate reading a real Schema entity, so removing or weakening the gate fails
 * the test instead of agreeing with it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\DeleteObject;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Locks the accounting and the archival refusal of the bulk delete loop.
 */
class ObjectServiceBulkDeleteTest extends TestCase {

	/**
	 * The archival retention annotation a schema carries to become immutable.
	 *
	 * @var array<string, mixed>
	 */
	private const ARCHIVAL_CONFIGURATION = [
		'x-openregister-archival' => ['retention' => ['default' => 'P10Y']],
	];

	private ObjectService $service;

	private DeleteObject&MockObject $deleteHandler;

	/**
	 * Schema entities this test's SchemaMapper double resolves, keyed by id.
	 *
	 * @var array<int, Schema>
	 */
	private array $schemas = [];

	/**
	 * Register/schema ids of the objects the mappers resolve, keyed by uuid.
	 *
	 * @var array<string, array{register: int, schema: int}>
	 */
	private array $scopes = [];

	/**
	 * Build a Schema entity with a given id and configuration.
	 *
	 * @param int   $id            The schema id.
	 * @param array $configuration The schema configuration.
	 *
	 * @return Schema The entity.
	 */
	private function schema(int $id, array $configuration): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug('schema-'.$id);
		$schema->setConfiguration($configuration);

		return $schema;
	}//end schema()

	/**
	 * Register the scope and schema of one object this test operates on.
	 *
	 * @param string $uuid          The object's uuid.
	 * @param int    $schemaId      The schema the object belongs to.
	 * @param array  $configuration The schema's configuration.
	 *
	 * @return ObjectEntity The entity the mappers will resolve for this uuid.
	 */
	private function givenObject(string $uuid, int $schemaId, array $configuration = []): ObjectEntity {
		$this->schemas[$schemaId] = $this->schema(id: $schemaId, configuration: $configuration);
		$this->scopes[$uuid]      = ['register' => 7, 'schema' => $schemaId];

		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setRegister('7');
		$object->setSchema((string) $schemaId);

		return $object;
	}//end givenObject()

	/**
	 * Assemble an ObjectService carrying only the collaborators deleteObjects() uses.
	 *
	 * ObjectService takes ~40 constructor arguments; the bulk delete loop touches
	 * seven of them, so the rest are left uninitialised rather than doubled.
	 *
	 * @param array<int, ObjectEntity> $resolvable Objects the mappers can resolve.
	 *
	 * @return void
	 */
	private function buildService(array $resolvable): void {
		$permissionHandler = $this->createMock(PermissionHandler::class);
		$permissionHandler->method('filterUuidsForPermissions')
			->willReturnCallback(
				static function (array $uuids) use ($resolvable): array {
					$known = array_map(static fn (ObjectEntity $o): string => (string) $o->getUuid(), $resolvable);

					return array_values(array_intersect($uuids, $known));
				}
			);

		$objectMapper = $this->createMock(MagicMapper::class);
		$objectMapper->method('findMultipleAcrossAllMagicTables')->willReturn($resolvable);

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(
			function (int|string $id): Schema {
				return $this->schemas[(int) $id];
			}
		);

		$register = new Register();
		$register->setId(7);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn($register);

		$this->deleteHandler = $this->createMock(DeleteObject::class);
		$this->deleteHandler->method('getLastCascadeCount')->willReturn(0);

		$this->service = (new ReflectionClass(ObjectService::class))->newInstanceWithoutConstructor();
		$this->inject('permissionHandler', $permissionHandler);
		$this->inject('objectMapper', $objectMapper);
		$this->inject('schemaMapper', $schemaMapper);
		$this->inject('registerMapper', $registerMapper);
		$this->inject('deleteHandler', $this->deleteHandler);
		$this->inject('cacheHandler', $this->createMock(CacheHandler::class));
		$this->inject('logger', $this->createMock(LoggerInterface::class));
	}//end buildService()

	/**
	 * Set one private property on the service under test.
	 *
	 * @param string $name  Property name.
	 * @param mixed  $value Value to set.
	 *
	 * @return void
	 */
	private function inject(string $name, mixed $value): void {
		$property = (new ReflectionClass(ObjectService::class))->getProperty($name);
		$property->setValue($this->service, $value);
	}//end inject()

	/**
	 * Every requested UUID is accounted for, and a permitted row is really deleted.
	 *
	 * `stays` is a row whose delete handler answers false without throwing — a
	 * RESTRICT-adjacent refusal the handler reports by return value. It used to
	 * fall out of both buckets, so `requested_count` exceeded
	 * `deleted_count + skipped_count` and the caller could not tell which row
	 * survived.
	 *
	 * @return void
	 */
	public function testEveryRequestedUuidLandsInExactlyOneBucket(): void {
		$goes  = $this->givenObject(uuid: 'uuid-goes', schemaId: 11);
		$stays = $this->givenObject(uuid: 'uuid-stays', schemaId: 11);
		$this->buildService([$goes, $stays]);

		$this->deleteHandler->method('deleteObject')->willReturnCallback(
			static fn (mixed ...$args): bool => ($args[2] ?? null) === 'uuid-goes'
		);

		$result = $this->service->deleteObjects(['uuid-goes', 'uuid-stays']);

		$this->assertSame(['uuid-goes'], $result['deleted_uuids'], 'the permitted row is deleted');
		$this->assertSame(['uuid-stays'], $result['skipped_uuids'], 'the refused row is named as skipped');
		$this->assertCount(
			2,
			array_merge($result['deleted_uuids'], $result['skipped_uuids']),
			'requested must equal deleted + skipped'
		);
	}//end testEveryRequestedUuidLandsInExactlyOneBucket()

	/**
	 * A UUID the permission filter removes is reported, not dropped.
	 *
	 * @return void
	 */
	public function testUuidRefusedByThePermissionFilterIsReportedAsSkipped(): void {
		$permitted = $this->givenObject(uuid: 'uuid-permitted', schemaId: 11);
		$this->buildService([$permitted]);

		$this->deleteHandler->method('deleteObject')->willReturn(true);

		$result = $this->service->deleteObjects(['uuid-permitted', 'uuid-forbidden']);

		$this->assertSame(['uuid-permitted'], $result['deleted_uuids']);
		$this->assertSame(['uuid-forbidden'], $result['skipped_uuids']);
	}//end testUuidRefusedByThePermissionFilterIsReportedAsSkipped()

	/**
	 * A row on an archival schema is refused, named, and left intact —
	 * while the rest of the batch is still processed.
	 *
	 * The refusal is decided by `Schema::hasArchivalAnnotation()` reading the real
	 * configuration below, so this fails if the gate is removed rather than
	 * agreeing with a copy of its condition.
	 *
	 * @return void
	 */
	public function testArchivalRowIsRefusedWhileTheRestOfTheBatchProceeds(): void {
		$archival = $this->givenObject(
			uuid: 'uuid-archival',
			schemaId: 12,
			configuration: self::ARCHIVAL_CONFIGURATION
		);
		$plain = $this->givenObject(uuid: 'uuid-plain', schemaId: 11);
		$this->buildService([$archival, $plain]);

		// The archival row must never reach the destructive call at all.
		$this->deleteHandler->expects($this->once())
			->method('deleteObject')
			->with($this->anything(), $this->anything(), 'uuid-plain')
			->willReturn(true);

		$result = $this->service->deleteObjects(['uuid-archival', 'uuid-plain']);

		$this->assertSame(['uuid-plain'], $result['deleted_uuids'], 'the rest of the batch still runs');
		$this->assertSame(['uuid-archival'], $result['skipped_uuids'], 'the refused row is named');
		$this->assertSame(
			'SCHEMA_ARCHIVAL_IMMUTABLE',
			$result['skipped_reasons']['uuid-archival']['error'] ?? null,
			'the refusal says why'
		);
	}//end testArchivalRowIsRefusedWhileTheRestOfTheBatchProceeds()

	/**
	 * NEGATIVE CONTROL: the same row on a schema WITHOUT the annotation is deleted.
	 *
	 * The only difference from the test above is the schema configuration. Without
	 * this, a gate that refused every bulk delete outright would look correct.
	 *
	 * @return void
	 */
	public function testRowOnANonArchivalSchemaIsNotRefused(): void {
		$object = $this->givenObject(
			uuid: 'uuid-archival',
			schemaId: 12,
			configuration: ['x-openregister-lifecycle' => ['default' => 'P10Y']]
		);
		$this->buildService([$object]);

		$this->deleteHandler->expects($this->once())
			->method('deleteObject')
			->willReturn(true);

		$result = $this->service->deleteObjects(['uuid-archival']);

		$this->assertSame(['uuid-archival'], $result['deleted_uuids']);
		$this->assertSame([], $result['skipped_uuids']);
	}//end testRowOnANonArchivalSchemaIsNotRefused()
}//end class
