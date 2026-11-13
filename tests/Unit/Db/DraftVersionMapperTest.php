<?php

/**
 * DraftVersionMapperTest — the draft store's two reads, and the table its
 * migration creates.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/records-draft-versions/design.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\DraftVersion;
use OCA\OpenRegister\Db\DraftVersionMapper;
use OCA\OpenRegister\Migration\Version1Date20261113140200;
use OCA\OpenRegister\Tests\Support\SchemaTableMockTrait;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\DraftVersionMapper
 * @covers \OCA\OpenRegister\Migration\Version1Date20261113140200
 * @uses   \OCA\OpenRegister\Db\DraftVersion
 */
final class DraftVersionMapperTest extends TestCase {
	use RecordingQueryBuilderTrait;
	use SchemaTableMockTrait;

	private function mapper(): DraftVersionMapper {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->recordingBuilder());
		return new DraftVersionMapper($db);
	}

	/**
	 * A stored draft row.
	 *
	 * @param string $key The key.
	 *
	 * @return array<string, mixed>
	 */
	private function row(string $key): array {
		return [
			'id' => 4,
			'uuid' => '1f0c2b3a-4d5e-4f60-8a7b-9c8d7e6f5a4b',
			'object_uuid' => 'obj-1',
			'draft_key' => $key,
			'name' => 'Status update',
			'created_by' => 'kees',
			'base_version' => '1.0.3',
			'delta' => '{"status":"in_behandeling"}',
		];
	}

	public function testTheDraftsOfOneObjectAreReadOldestFirst(): void {
		$this->queuedRows = [[$this->row('a'), $this->row('b')]];

		$drafts = $this->mapper()->findForObject(objectUuid: 'obj-1');

		$this->assertCount(2, $drafts);
		$this->assertInstanceOf(DraftVersion::class, $drafts[0]);
		$this->assertSame(['status' => 'in_behandeling'], $drafts[0]->getDelta());
		$this->assertSame('b', $drafts[1]->getDraftKey());
		$this->assertSame(
			[
				'#1 select(*)',
				'#1 from(openregister_object_drafts, NULL)',
				'#1 param :p1 = obj-1, 2',
				'#1 expr.eq(object_uuid, :p1, NULL)',
				'#1 where(eq(object_uuid, :p1, NULL))',
				'#1 orderBy(id, ASC)',
				'#1 executeQuery()',
			],
			$this->queryLog
		);
	}

	public function testADraftIsFoundByObjectAndKey(): void {
		$this->queuedRows = [[$this->row('status-update')]];

		$draft = $this->mapper()->findByKey(objectUuid: 'obj-1', key: 'status-update');

		$this->assertSame('status-update', $draft?->getDraftKey());
		$this->assertContains('#1 where(eq(object_uuid, :p1, NULL))', $this->queryLog);
		$this->assertContains('#1 andWhere(eq(draft_key, :p2, NULL))', $this->queryLog);
		$this->assertContains('#1 param :p2 = status-update, 2', $this->queryLog);
	}

	public function testAKeyWithoutADraftIsNull(): void {
		$this->queuedRows = [[]];

		$this->assertNull($this->mapper()->findByKey(objectUuid: 'obj-1', key: 'nope'));
	}

	public function testTheMigrationCreatesTheTableOnceWithAUniqueKeyPerObject(): void {
		$columns = [];
		$unique = [];
		$table = $this->createTableMock();
		$table->method('addColumn')->willReturnCallback(
			function (string $name) use (&$columns) {
				$columns[] = $name;
				return $this->createColumnMock();
			}
		);
		$table->method('addUniqueIndex')->willReturnCallback(
			function (array $columnNames, string $indexName) use (&$unique, $table) {
				$unique[$indexName] = $columnNames;
				return $table;
			}
		);
		$table->expects($this->once())->method('setPrimaryKey')->with(['id']);

		$created = false;
		$wrapper = $this->createMock(ISchemaWrapper::class);
		$wrapper->method('hasTable')->with('openregister_object_drafts')->willReturnCallback(
			function () use (&$created): bool {
				return $created;
			}
		);
		$wrapper->expects($this->once())->method('createTable')->with('openregister_object_drafts')->willReturnCallback(
			function () use (&$created, $table) {
				$created = true;
				return $table;
			}
		);
		$migration = new Version1Date20261113140200();

		$this->assertSame($wrapper, $migration->changeSchema($this->createMock(IOutput::class), fn () => $wrapper, []));
		$this->assertSame(
			['id', 'uuid', 'object_uuid', 'object_register', 'object_schema', 'draft_key', 'name', 'created_by', 'base_version', 'delta', 'created', 'updated'],
			$columns
		);
		$this->assertSame(['idx_or_objdraft_uuid' => ['uuid'], 'idx_or_objdraft_key' => ['object_uuid', 'draft_key']], $unique);

		// Run again: the table exists, nothing is created.
		$this->assertSame($wrapper, $migration->changeSchema($this->createMock(IOutput::class), fn () => $wrapper, []));
	}
}
