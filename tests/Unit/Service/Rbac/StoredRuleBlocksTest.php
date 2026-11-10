<?php

/**
 * Tests for StoredRuleBlocks.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
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

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Rbac\SchemaSave;
use OCA\OpenRegister\Service\Rbac\StoredRuleBlocks;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Reading the rule blocks as they are stored, before a save changes them.
 *
 * @covers \OCA\OpenRegister\Service\Rbac\StoredRuleBlocks
 */
class StoredRuleBlocksTest extends TestCase {

	/**
	 * A connection whose one query returns the given row, recording the table and id.
	 *
	 * @param mixed                $row      What fetch() returns.
	 * @param array<string, mixed> $recorded Receives the table and bound id.
	 *
	 * @return IDBConnection
	 */
	private function db(mixed $row, array &$recorded): IDBConnection {
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn($row);
		$result->expects($this->once())->method('closeCursor');

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(static fn (string $col, string $p): string => $col . '=' . $p);

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnCallback(
			static function (string $table) use (&$recorded, $qb): IQueryBuilder {
				$recorded['table'] = $table;
				return $qb;
			}
		);
		$qb->method('where')->willReturnCallback(
			static function (string $where) use (&$recorded, $qb): IQueryBuilder {
				$recorded['where'] = $where;
				return $qb;
			}
		);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(
			static function ($value, $type) use (&$recorded): string {
				$recorded['id']   = $value;
				$recorded['type'] = $type;
				return ':id';
			}
		);
		$qb->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		return $db;
	}//end db()

	/**
	 * The stored schema carries the decoded authorization and properties of its row.
	 *
	 * @return void
	 */
	public function testSchemaIsReadFromItsStoredRow(): void {
		$recorded = [];
		$blocks   = new StoredRuleBlocks(db: $this->db(
			row: ['authorization' => '{"read":["public"]}', 'properties' => '{"name":{"type":"string"}}'],
			recorded: $recorded
		));

		$schema = $blocks->schema(id: 4);

		$this->assertSame(['read' => ['public']], $schema->getAuthorization());
		$this->assertSame(['name' => ['type' => 'string']], $schema->getProperties());
		$this->assertSame(
			['table' => 'openregister_schemas', 'id' => 4, 'type' => IQueryBuilder::PARAM_INT, 'where' => 'id=:id'],
			$recorded
		);
	}//end testSchemaIsReadFromItsStoredRow()

	/**
	 * Undecodable or non-string columns read as no block.
	 *
	 * @return void
	 */
	public function testUndecodableColumnsReadAsNull(): void {
		$recorded = [];
		$schema   = (new StoredRuleBlocks(db: $this->db(row: ['authorization' => 'not json', 'properties' => 5], recorded: $recorded)))
			->schema(id: 4);

		$this->assertNull($schema->getAuthorization());
		$this->assertSame([], $schema->getProperties() ?? []);
	}//end testUndecodableColumnsReadAsNull()

	/**
	 * A missing row is no stored schema.
	 *
	 * @return void
	 */
	public function testMissingRowIsNoSchema(): void {
		$recorded = [];
		$this->assertNull((new StoredRuleBlocks(db: $this->db(row: false, recorded: $recorded)))->schema(id: 9));
	}//end testMissingRowIsNoSchema()

	/**
	 * A new entity (no id) or no connection is never looked up.
	 *
	 * @return void
	 */
	public function testNoIdOrNoConnectionReadsNothing(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->never())->method('getQueryBuilder');

		$this->assertNull((new StoredRuleBlocks(db: $db))->schema(id: null));
		$this->assertNull((new StoredRuleBlocks(db: $db))->registerAuthorization(id: null));
		$this->assertNull((new StoredRuleBlocks())->schema(id: 3));
	}//end testNoIdOrNoConnectionReadsNothing()

	/**
	 * The register's stored authorization is read from the registers table.
	 *
	 * @return void
	 */
	public function testRegisterAuthorizationIsReadFromItsRow(): void {
		$recorded = [];
		$blocks   = new StoredRuleBlocks(db: $this->db(row: ['authorization' => '{"create":["staff"]}'], recorded: $recorded));

		$this->assertSame(['create' => ['staff']], $blocks->registerAuthorization(id: 2));
		$this->assertSame('openregister_registers', $recorded['table']);
		$this->assertSame(2, $recorded['id']);
	}//end testRegisterAuthorizationIsReadFromItsRow()

	/**
	 * Only properties with an authorization block are listed, paired with what is stored.
	 *
	 * @return void
	 */
	public function testPropertyBlocksPairIncomingWithStored(): void {
		$schema = new Schema();
		$schema->setProperties(
			[
				'bsn'   => ['type' => 'string', 'authorization' => ['read' => ['hr']]],
				'name'  => ['type' => 'string'],
				'flag'  => ['authorization' => 'not-a-block'],
				'notes' => ['authorization' => ['read' => ['staff']]],
				'raw'   => 'not-an-array',
			]
		);
		$stored = new Schema();
		$stored->setProperties(
			[
				'bsn'   => ['authorization' => ['read' => ['admin']]],
				'notes' => ['authorization' => 'corrupt'],
			]
		);

		$this->assertSame(
			[
				['authorization' => ['read' => ['hr']], 'context' => 'property "bsn" of schema X', 'stored' => ['read' => ['admin']]],
				['authorization' => ['read' => ['staff']], 'context' => 'property "notes" of schema X', 'stored' => null],
			],
			(new StoredRuleBlocks())->propertyBlocks(save: new SchemaSave(schema: $schema, stored: $stored), name: 'schema X')
		);
	}//end testPropertyBlocksPairIncomingWithStored()

	/**
	 * A schema with no stored counterpart lists every block as new.
	 *
	 * @return void
	 */
	public function testPropertyBlocksWithoutStoredSchema(): void {
		$schema = new Schema();
		$schema->setProperties(['bsn' => ['authorization' => ['read' => ['hr']]]]);

		$this->assertSame(
			[['authorization' => ['read' => ['hr']], 'context' => 'property "bsn" of new', 'stored' => null]],
			(new StoredRuleBlocks())->propertyBlocks(save: new SchemaSave(schema: $schema, stored: null), name: 'new')
		);
	}//end testPropertyBlocksWithoutStoredSchema()
}//end class
