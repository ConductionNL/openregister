<?php

/**
 * The object half of the reference rewrite: folders and file properties, one audit entry per object.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Service\File\DocumentReferenceMap;
use OCA\OpenRegister\Service\File\DocumentStoreObjects;
use OCA\OpenRegister\Service\WholeTableRows;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * One register, one schema with a file property, three objects.
 */
#[CoversClass(DocumentStoreObjects::class)]
#[UsesClass(DocumentReferenceMap::class)]
#[UsesClass(WholeTableRows::class)]
#[UsesClass(RegisterSchema::class)]
#[UsesClass(ListQuery::class)]
#[UsesClass(ObjectEntity::class)]
#[UsesClass(Register::class)]
#[UsesClass(Schema::class)]
class DocumentStoreObjectsTest extends TestCase {

	private static function object(string $uuid, ?string $folder, array $data): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setFolder($folder);
		$object->setObject($data);
		return $object;
	}//end object()

	public function testEachChangedObjectGetsOneAuditEntry(): void {
		$register = new Register();
		$register->setId(5);
		$register->setSchemas([8]);
		$schema = new Schema();
		$schema->setId(8);
		$schema->setProperties([
			'besluit' => ['type' => 'file'],
			'bijlagen' => ['type' => 'array', 'items' => ['type' => 'file']],
			'titel' => ['type' => 'string'],
		]);
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->expects($this->once())->method('find')->with(8, [], false, false)->willReturn($schema);

		$withFile = self::object(uuid: 'a', folder: '11', data: ['besluit' => 101, 'bijlagen' => ['102', 999], 'titel' => '101']);
		$folderOnly = self::object(uuid: 'b', folder: '12', data: ['besluit' => null, 'titel' => 'x']);
		$untouched = self::object(uuid: 'c', folder: '77', data: ['besluit' => 555, 'titel' => 'y']);
		$mapper = $this->createMock(MagicMapperInterface::class);
		$mapper->method('findAllInRegisterSchemaTable')->willReturnCallback(
			function (RegisterSchema $table, ListQuery $listing) use ($withFile, $folderOnly, $untouched): array {
				$this->assertFalse($listing->filters['_rbac'], 'Every row is read, in system context.');
				return [$withFile, $folderOnly, $untouched];
			}
		);
		$stored = [];
		$mapper->method('update')->willReturnCallback(function (ObjectEntity $entity, $reg, $sch, $old) use (&$stored, $register, $schema): ObjectEntity {
			$this->assertSame($register, $reg);
			$this->assertSame($schema, $sch);
			$stored[] = [$entity->getUuid(), $old->getFolder(), $old->getObject()];
			return $entity;
		});
		$audits = $this->createMock(AuditTrailMapper::class);
		$entries = [];
		$audits->method('createAuditTrail')->willReturnCallback(function ($old, $new, $action, $context) use (&$entries): AuditTrail {
			$entries[] = [$new->getUuid(), $action, $context];
			return new AuditTrail();
		});

		$objects = new DocumentStoreObjects(
			schemaMapper: $schemas,
			rows: new WholeTableRows(mapper: $mapper, db: $this->createMock(IDBConnection::class)),
			objects: $mapper,
			auditTrails: $audits,
			map: new DocumentReferenceMap(),
		);
		$changed = $objects->rewrite(register: $register, moved: [11 => 911, 12 => 912, 101 => 9101, 102 => 9102], withoutVersions: [102 => 102]);

		$this->assertSame(2, $changed);
		$this->assertSame('911', $withFile->getFolder());
		$this->assertSame(['id' => 'a', 'besluit' => 9101, 'bijlagen' => ['9102', 999], 'titel' => '101'], $withFile->getObject());
		$this->assertSame('912', $folderOnly->getFolder());
		$this->assertSame('77', $untouched->getFolder());
		$this->assertSame(['id' => 'c', 'besluit' => 555, 'titel' => 'y'], $untouched->getObject());
		$this->assertSame(
			[
				['a', '11', ['id' => 'a', 'besluit' => 101, 'bijlagen' => ['102', 999], 'titel' => '101']],
				['b', '12', ['id' => 'b', 'besluit' => null, 'titel' => 'x']],
			],
			$stored,
			'The old state is passed with each update.'
		);
		$this->assertSame(
			[
				['a', 'document-store-move', ['documentStoreMove' => ['withoutEarlierVersions' => [102]]]],
				['b', 'document-store-move', null],
			],
			$entries
		);
	}//end testEachChangedObjectGetsOneAuditEntry()
}//end class
