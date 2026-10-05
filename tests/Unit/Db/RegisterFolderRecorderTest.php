<?php

/**
 * RegisterFolderRecorder over a query-builder double: the statement it builds
 * is a single-column compare-and-set, and its answer is whether a row changed.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/file-actions/spec.md#requirement-recording-a-registers-folder-id-is-bookkeeping-req-rffu-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\RegisterFolderRecorder;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The folder id write.
 */
class RegisterFolderRecorderTest extends TestCase {

	private IDBConnection&MockObject $db;

	private IQueryBuilder&MockObject $qb;

	private RegisterFolderRecorder $recorder;

	/**
	 * Every builder call, in order, with its arguments rendered as text.
	 *
	 * @var list<string>
	 */
	private array $calls = [];

	/**
	 * How each composite expression the double handed out reads, by object id.
	 *
	 * @var array<int, string>
	 */
	private array $composites = [];

	protected function setUp(): void {
		parent::setUp();
		$this->db = $this->createMock(IDBConnection::class);
		$this->qb = $this->createMock(IQueryBuilder::class);
		foreach (['update', 'set', 'select', 'from', 'where', 'andWhere', 'setMaxResults'] as $fluent) {
			$this->qb->method($fluent)->willReturnCallback(function (mixed ...$arguments) use ($fluent): IQueryBuilder {
				// Defaulted arguments (an alias left out) arrive as null and are not part of the statement.
				$this->calls[] = $fluent . '(' . implode(', ', array_map(fn (mixed $argument): string => $this->render($argument), array_filter($arguments, static fn (mixed $argument): bool => $argument !== null))) . ')';
				return $this->qb;
			});
		}

		// A named parameter renders as its value, so the statement reads back as SQL-ish text.
		$this->qb->method('createNamedParameter')->willReturnCallback(static fn (mixed $value): string => var_export($value, true));

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(static fn (string $column, string $value): string => "$column = $value");
		$expr->method('isNull')->willReturnCallback(static fn (string $column): string => "$column IS NULL");
		$expr->method('neq')->willReturnCallback(static fn (string $column, string $value): string => "$column <> $value");
		$expr->method('orX')->willReturnCallback(
			function (string ...$parts): ICompositeExpression {
				$composite = $this->createMock(ICompositeExpression::class);
				$this->composites[spl_object_id($composite)] = '(' . implode(' OR ', $parts) . ')';
				return $composite;
			}
		);
		$this->qb->method('expr')->willReturn($expr);

		$this->db->method('getQueryBuilder')->willReturn($this->qb);
		$this->recorder = new RegisterFolderRecorder(db: $this->db);
	}//end setUp()

	/**
	 * A builder argument as text: a composite by what it holds, anything else as a string.
	 *
	 * @param mixed $argument The argument.
	 *
	 * @return string
	 */
	private function render(mixed $argument): string {
		if ($argument instanceof ICompositeExpression) {
			return $this->composites[spl_object_id($argument)];
		}

		return (string)$argument;
	}//end render()

	/**
	 * The write sets the folder column of one register, only while it is empty or still what was read.
	 *
	 * @return void
	 */
	public function testItWritesOnlyTheFolderColumnOfOneRegisterWithACompareAndSet(): void {
		$this->qb->method('executeStatement')->willReturn(1);

		$this->recorder->record(registerId: 7, expected: '/legacy/path', folderId: '501');

		$this->assertSame(
			[
				'update(openregister_registers)',
				"set(folder, '501')",
				'where(id = 7)',
				"andWhere((folder IS NULL OR folder = '/legacy/path'))",
			],
			$this->calls
		);
	}//end testItWritesOnlyTheFolderColumnOfOneRegisterWithACompareAndSet()

	/**
	 * A register read with no folder matches both a NULL and an empty stored value.
	 *
	 * @return void
	 */
	public function testNoFolderReadMatchesNullAndEmpty(): void {
		$this->qb->method('executeStatement')->willReturn(1);

		$this->recorder->record(registerId: 7, expected: null, folderId: '501');

		$this->assertSame("andWhere((folder IS NULL OR folder = ''))", $this->calls[3]);
	}//end testNoFolderReadMatchesNullAndEmpty()

	/**
	 * One changed row means this call recorded the folder.
	 *
	 * @return void
	 */
	public function testItAnswersTrueWhenARowChanged(): void {
		$this->qb->method('executeStatement')->willReturn(1);

		$this->assertTrue($this->recorder->record(registerId: 7, expected: '', folderId: '501'));
	}//end testItAnswersTrueWhenARowChanged()

	/**
	 * No changed row means another request recorded a folder first, and nothing was overwritten.
	 *
	 * @return void
	 */
	public function testItAnswersFalseWhenAnotherRequestRecordedFirst(): void {
		$this->qb->method('executeStatement')->willReturn(0);

		$this->assertFalse($this->recorder->record(registerId: 7, expected: null, folderId: '502'));
	}//end testItAnswersFalseWhenAnotherRequestRecordedFirst()

	/**
	 * The shared-folder question looks for any other register row with the folder id, with no RBAC filter.
	 *
	 * @return void
	 */
	public function testItAsksWhetherAnyOtherRegisterRecordsTheFolder(): void {
		$result = $this->createMock(IResult::class);
		$result->method('fetchOne')->willReturn(9);
		$this->qb->method('executeQuery')->willReturn($result);

		$this->assertTrue($this->recorder->isRecordedByAnotherRegister(folderId: '501', registerId: 7));
		$this->assertSame(
			[
				'select(id)',
				'from(openregister_registers)',
				"where(folder = '501')",
				'andWhere(id <> 7)',
				'setMaxResults(1)',
			],
			$this->calls
		);
	}//end testItAsksWhetherAnyOtherRegisterRecordsTheFolder()

	/**
	 * No other row with the folder id means the folder is this register's alone.
	 *
	 * @return void
	 */
	public function testItAnswersFalseWhenNoOtherRegisterRecordsTheFolder(): void {
		$result = $this->createMock(IResult::class);
		$result->method('fetchOne')->willReturn(false);
		$this->qb->method('executeQuery')->willReturn($result);

		$this->assertFalse($this->recorder->isRecordedByAnotherRegister(folderId: '501', registerId: 7));
	}//end testItAnswersFalseWhenNoOtherRegisterRecordsTheFolder()
}//end class
