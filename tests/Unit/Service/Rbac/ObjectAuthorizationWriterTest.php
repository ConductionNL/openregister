<?php

/**
 * Tests for ObjectAuthorizationWriter.
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

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Rbac\ObjectAuthorizationWriter;
use OCA\OpenRegister\Service\Rbac\AuthorizationAssignment;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The single-column `_authorization` update.
 *
 * @covers \OCA\OpenRegister\Service\Rbac\ObjectAuthorizationWriter
 */
class ObjectAuthorizationWriterTest extends TestCase {

	/**
	 * Run one write and record what the query builder was told.
	 *
	 * @param array<string, mixed> $block The block to write.
	 *
	 * @return array{calls: array<int, array<int, mixed>>, log: array<string, mixed>}
	 */
	private function write(array $block): array {
		$register = new Register();
		$schema   = new Schema();
		$calls    = [];

		$mapper = $this->createMock(MagicMapperInterface::class);
		$mapper->expects($this->once())->method('getTableNameForRegisterSchema')
			->with($register, $schema)
			->willReturn('openregister_table_1_2');

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(
			static function (string $col, string $param) use (&$calls): string {
				$calls[] = ['eq', $col, $param];
				return $col . '=' . $param;
			}
		);

		$qb = $this->createMock(IQueryBuilder::class);
		$params = 0;
		$qb->method('createNamedParameter')->willReturnCallback(
			static function ($value) use (&$calls, &$params): string {
				$params++;
				$calls[] = ['param', $value];
				return ':p' . $params;
			}
		);
		$qb->method('expr')->willReturn($expr);
		$qb->method('update')->willReturnCallback(
			static function (string $table) use (&$calls, $qb): IQueryBuilder {
				$calls[] = ['update', $table];
				return $qb;
			}
		);
		$qb->method('set')->willReturnCallback(
			static function (string $col, $value) use (&$calls, $qb): IQueryBuilder {
				$calls[] = ['set', $col, $value];
				return $qb;
			}
		);
		$qb->method('where')->willReturnCallback(
			static function ($where) use (&$calls, $qb): IQueryBuilder {
				$calls[] = ['where', $where];
				return $qb;
			}
		);
		$qb->expects($this->once())->method('executeStatement')->willReturn(1);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$log    = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->willReturnCallback(
			static function (string $message, array $context) use (&$log): void {
				$log = ['message' => $message, 'uuid' => $context['uuid'], 'scope' => $context['scope']];
			}
		);

		$writer = new ObjectAuthorizationWriter(mapper: $mapper, db: $db, logger: $logger);
		$writer->writeAuthorizationBlock(table: new RegisterSchema(register: $register, schema: $schema), assignment: new AuthorizationAssignment(objectUuid: 'uuid-1', block: $block));

		return ['calls' => $calls, 'log' => $log];
	}//end write()

	/**
	 * Only `_authorization` is set, on the row with this uuid, in the pair's table.
	 *
	 * @return void
	 */
	public function testWritesOnlyTheAuthorizationColumnOfOneRow(): void {
		$run = $this->write(block: ['scope' => 'private', 'read' => ['alice']]);

		$this->assertSame(
			[
				['update', 'openregister_table_1_2'],
				['param', '{"scope":"private","read":["alice"]}'],
				['set', '_authorization', ':p1'],
				['param', 'uuid-1'],
				['eq', '_uuid', ':p2'],
				['where', '_uuid=:p2'],
			],
			$run['calls']
		);
		$this->assertSame('uuid-1', $run['log']['uuid']);
		$this->assertSame('private', $run['log']['scope']);
	}//end testWritesOnlyTheAuthorizationColumnOfOneRow()

	/**
	 * A block without a scope is still written and logs a null scope.
	 *
	 * @return void
	 */
	public function testBlockWithoutScopeLogsNullScope(): void {
		$run = $this->write(block: []);

		$this->assertSame(['param', '[]'], $run['calls'][1]);
		$this->assertNull($run['log']['scope']);
	}//end testBlockWithoutScopeLogsNullScope()
}//end class
