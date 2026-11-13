<?php

/**
 * ExpiredObjectStore: which tables it sweeps and how it deletes, anonymises and audits.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Retention
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/expired-object-sweep/specs/expired-object-sweep/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Retention;

use DateTime;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectDeletion;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\Retention\ExpiredObjectSources;
use OCA\OpenRegister\Service\Retention\ExpiredObjectStore;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Only opted-in schemas with a table are swept; a delete goes through the
 * object service as the system; an anonymisation removes the files, clears
 * the expiry and writes straight to the mapper; audit entries are the system's.
 *
 * Finding and counting expired rows is SQL and is left to a database test.
 *
 * @covers \OCA\OpenRegister\Service\Retention\ExpiredObjectStore
 * @uses   \OCA\OpenRegister\Service\Retention\ExpiredObjectSources
 * @uses   \OCA\OpenRegister\Service\Retention\ExpiryDeclaration
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\ObjectDeletion
 */
final class ExpiredObjectStoreTest extends TestCase {

	private RegisterMapperInterface&MockObject $registers;

	private SchemaMapperInterface&MockObject $schemas;

	private MagicMapperInterface&MockObject $magic;

	private AuditTrailMapper&MockObject $audit;

	private ObjectOperationsInterface&MockObject $objects;

	private FileService&MockObject $files;

	private ExpiredObjectStore $store;

	/**
	 * Build the store over mocked mappers.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->registers = $this->createMock(RegisterMapperInterface::class);
		$this->schemas   = $this->createMock(SchemaMapperInterface::class);
		$this->magic     = $this->createMock(MagicMapperInterface::class);
		$this->audit     = $this->createMock(AuditTrailMapper::class);
		$this->objects   = $this->createMock(ObjectOperationsInterface::class);
		$this->files     = $this->createMock(FileService::class);
		$this->store     = new ExpiredObjectStore(
			sources: new ExpiredObjectSources(
				registers: $this->registers,
				schemas: $this->schemas,
				magic: $this->magic,
				audit: $this->audit,
				db: $this->createMock(IDBConnection::class)
			),
			objects: $this->objects,
			files: $this->files,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * A schema.
	 *
	 * @param int        $id     The id.
	 * @param array|null $expiry The expiry block, or null.
	 *
	 * @return Schema
	 */
	private static function schema(int $id, ?array $expiry): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setProperties(['reference' => []]);
		$schema->setConfiguration($expiry === null ? [] : ['x-openregister' => ['expiry' => $expiry]]);
		return $schema;
	}//end schema()

	/**
	 * Only opted-in, valid, materialised schemas are returned, across registers.
	 *
	 * @return void
	 */
	public function testOnlyOptedInTablesAreSwept(): void {
		$portal = new Register();
		$portal->setSchemas([1, 2, 3, 4, 5]);
		$this->registers->method('findAll')->with(null, null, [], [], [], false, false)->willReturn([$portal, 'not a register']);

		$byId = [
			1 => self::schema(1, ['action' => 'delete']),
			2 => self::schema(2, null),
			3 => self::schema(3, ['action' => 'remove']),
			4 => self::schema(4, ['action' => 'anonymise', 'keep' => ['reference']]),
		];
		$this->schemas->method('find')->willReturnCallback(
			static function (int|string $id) use ($byId): Schema {
				if (isset($byId[(int)$id]) === false) {
					throw new \RuntimeException('gone');
				}

				return $byId[(int)$id];
			}
		);
		$this->magic->method('tableExistsForRegisterSchema')->willReturnCallback(static fn (Register $r, Schema $s): bool => $s->getId() === 1);

		$tables = $this->store->optedInTables();

		$this->assertCount(1, $tables);
		$this->assertSame($portal, $tables[0]['register']);
		$this->assertSame(1, $tables[0]['schema']->getId());
	}//end testOnlyOptedInTablesAreSwept()

	/**
	 * A delete runs through the object service in the table's scope, without RBAC.
	 *
	 * @return void
	 */
	public function testADeleteGoesThroughTheObjectService(): void {
		$register = new Register();
		$schema   = self::schema(1, ['action' => 'delete']);
		$object   = new ObjectEntity();
		$object->setUuid('u-1');

		$this->objects->expects($this->once())->method('setRegister')->with($register)->willReturnSelf();
		$this->objects->expects($this->once())->method('setSchema')->with($schema)->willReturnSelf();
		$this->objects->expects($this->once())->method('deleteObject')
			->with($this->callback(static fn (ObjectDeletion $d): bool => $d->uuid === 'u-1'), false, false)
			->willReturn(true);

		$this->assertTrue($this->store->delete(register: $register, schema: $schema, object: $object));
	}//end testADeleteGoesThroughTheObjectService()

	/**
	 * An anonymisation removes every file, clears the expiry and writes the data straight to the mapper.
	 *
	 * @return void
	 */
	public function testAnAnonymisationRemovesFilesAndClearsTheExpiry(): void {
		$register = new Register();
		$schema   = self::schema(1, ['action' => 'anonymise']);
		$object   = new ObjectEntity();
		$object->setUuid('u-2');
		$object->setExpires(new DateTime('2026-01-01'));
		$object->setObject(['reference' => 'R', 'answers' => ['a' => 1]]);

		$fileA = $this->createMock(\OCP\Files\File::class);
		$fileB = $this->createMock(\OCP\Files\File::class);
		$this->files->method('getFiles')->with($object)->willReturn([$fileA, $fileB]);
		$deleted = [];
		$this->files->expects($this->exactly(2))->method('deleteFile')->willReturnCallback(
			static function ($file, $owner) use (&$deleted, $object): bool {
				$deleted[] = [$file, $owner === $object];
				return true;
			}
		);
		$this->magic->expects($this->once())->method('update')->with(
			$this->callback(static fn (ObjectEntity $e): bool => $e->getExpires() === null && $e->getObject()['answers'] === []),
			$register,
			$schema,
			$this->callback(static fn (ObjectEntity $old): bool => $old->getExpires() !== null)
		)->willReturnArgument(0);

		$this->store->anonymise(register: $register, schema: $schema, object: $object, data: ['reference' => 'R', 'answers' => []]);

		$this->assertSame([[$fileA, true], [$fileB, true]], $deleted);
		$this->assertNull($object->getExpires());
	}//end testAnAnonymisationRemovesFilesAndClearsTheExpiry()

	/**
	 * Audit entries are written as the system.
	 *
	 * @return void
	 */
	public function testAuditEntriesAreTheSystems(): void {
		$object = new ObjectEntity();
		$this->audit->expects($this->once())->method('createAuditTrailEntry')
			->with($object, 'expiry.deleted', ['expires' => '2026-01-01'], 'system', 'system');

		$this->store->audit(object: $object, action: 'expiry.deleted', context: ['expires' => '2026-01-01']);
	}//end testAuditEntriesAreTheSystems()
}//end class
