<?php

/**
 * Contract tests for {@see \OCA\OpenRegister\Service\Rbac\ObjectOwnerWriter}.
 *
 * The writer exists to be the ONE targeted write of the `_owner` column, and the
 * properties worth pinning are the ones that make it targeted: it updates the
 * magic table the register and schema resolve to, it keys the update on the
 * record's uuid, and it writes nothing but the owner. A handover that quietly
 * rewrote the record's data would be a data migration wearing an ownership label.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/object-ownership/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Rbac\ObjectOwnerWriter;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * ObjectOwnerWriterTest.
 */
class ObjectOwnerWriterTest extends TestCase {

	/**
	 * What the writer asked the query builder to do, in order.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $calls = [];

	/**
	 * Build a query-builder double that records the statement it was given.
	 *
	 * @return IQueryBuilder The recording double.
	 */
	private function recordingQueryBuilder(): IQueryBuilder {
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(
			function (string $x, $y): string {
				$this->calls[] = ['where' => $x, 'value' => $y];
				return $x . ' = ' . $y;
			}
		);

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(
			static function ($value): string {
				return (string)$value;
			}
		);
		$qb->method('update')->willReturnCallback(
			function (string $table) use ($qb): IQueryBuilder {
				$this->calls[] = ['update' => $table];
				return $qb;
			}
		);
		$qb->method('set')->willReturnCallback(
			function (string $column, $value) use ($qb): IQueryBuilder {
				$this->calls[] = ['set' => $column, 'value' => $value];
				return $qb;
			}
		);
		$qb->method('where')->willReturnCallback(
			function ($predicate) use ($qb): IQueryBuilder {
				$this->calls[] = ['wherePredicate' => (string)$predicate];
				return $qb;
			}
		);
		$qb->method('executeStatement')->willReturnCallback(
			function (): int {
				$this->calls[] = ['executed' => true];
				return 1;
			}
		);

		return $qb;
	}//end recordingQueryBuilder()

	/**
	 * Write one owner through a recording writer.
	 *
	 * @param string $uuid The record uuid.
	 * @param string $owner The new owner.
	 *
	 * @return void
	 */
	private function writeOwner(string $uuid, string $owner): void {
		$this->calls = [];

		$mapper = $this->createMock(MagicMapper::class);
		$mapper->method('getTableNameForRegisterSchema')->willReturn('oc_openregister_zaken_zaak');

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($this->recordingQueryBuilder());

		$writer = new ObjectOwnerWriter($mapper, $db, new NullLogger());
		$writer->writeOwner(new Register(), new Schema(), $uuid, $owner);
	}//end writeOwner()

	public function testItUpdatesTheTableTheRegisterAndSchemaResolveTo(): void {
		$this->writeOwner('uuid-1', 'carol');

		$this->assertSame('oc_openregister_zaken_zaak', $this->calls[0]['update']);
	}//end testItUpdatesTheTableTheRegisterAndSchemaResolveTo()

	public function testItWritesTheOwnerColumnAndNothingElse(): void {
		$this->writeOwner('uuid-1', 'carol');

		$sets = [];
		foreach ($this->calls as $call) {
			if (isset($call['set']) === true) {
				$sets[$call['set']] = $call['value'];
			}
		}

		$this->assertSame(['_owner' => 'carol'], $sets);
	}//end testItWritesTheOwnerColumnAndNothingElse()

	public function testItKeysTheUpdateOnTheRecordUuid(): void {
		$this->writeOwner('uuid-1', 'carol');

		$wheres = [];
		foreach ($this->calls as $call) {
			if (isset($call['where']) === true) {
				$wheres[$call['where']] = $call['value'];
			}
		}

		$this->assertSame(['_uuid' => 'uuid-1'], $wheres);
	}//end testItKeysTheUpdateOnTheRecordUuid()

	public function testItExecutesTheStatement(): void {
		$this->writeOwner('uuid-1', 'carol');

		$executed = false;
		foreach ($this->calls as $call) {
			if (isset($call['executed']) === true) {
				$executed = true;
			}
		}

		$this->assertTrue($executed, 'The owner write must reach the database');
	}//end testItExecutesTheStatement()

}//end class
