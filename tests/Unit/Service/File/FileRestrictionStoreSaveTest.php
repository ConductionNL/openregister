<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/file-actions/spec.md#requirement-a-file-in-an-objects-folder-can-carry-a-read-restriction-req-pfrr-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use DateTimeImmutable;
use OCA\OpenRegister\Files\Service\File\FileRestrictionStore;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Pins the row a stored restriction writes: an update of the existing row, else an insert.
 *
 * @spec openspec/specs/file-actions/spec.md#requirement-a-file-in-an-objects-folder-can-carry-a-read-restriction-req-pfrr-001
 */
class FileRestrictionStoreSaveTest extends TestCase {

	/**
	 * Every column written, per builder, in the order they were set.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $written = [];

	/**
	 * A query builder that records the columns it is given and answers $affected.
	 *
	 * @param int $affected The rows its statement reports.
	 *
	 * @return IQueryBuilder The builder.
	 */
	private function builder(int $affected): IQueryBuilder {
		$index = count($this->written);
		$this->written[$index] = [];
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('createNamedParameter')->willReturnCallback(static fn ($value) => $value);
		$qb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
		foreach (['update', 'insert', 'where'] as $method) {
			$qb->method($method)->willReturnSelf();
		}

		foreach (['set', 'setValue'] as $method) {
			$qb->method($method)->willReturnCallback(
				function (string $column, $value) use ($qb, $index) {
					$this->written[$index][$column] = $value;
					return $qb;
				}
			);
		}

		$qb->method('executeStatement')->willReturn($affected);
		return $qb;
	}//end builder()

	/**
	 * The store on a connection handing out the given builders.
	 *
	 * @param list<IQueryBuilder> $builders The builders, in order.
	 *
	 * @return FileRestrictionStore The store.
	 */
	private function store(array $builders): FileRestrictionStore {
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->exactly(count($builders)))->method('getQueryBuilder')->willReturnOnConsecutiveCalls(...$builders);
		return new FileRestrictionStore($db);
	}//end store()

	/**
	 * The columns every saved row carries.
	 *
	 * @return array<string, string> The columns.
	 */
	private function columns(): array {
		return [
			'user_ids' => '["pieter","1001"]',
			'group_ids' => '["woo-team"]',
			'reason' => 'Prices',
			'set_by' => 'pieter',
			'set_at' => '2026-01-02 03:04:05',
		];
	}//end columns()

	public function testAnExistingRowIsUpdatedInPlace(): void {
		$this->store([$this->builder(1)])->save(42, [1 => 'pieter', 3 => '1001'], ['woo-team'], 'Prices', 'pieter', new DateTimeImmutable('2026-01-02 03:04:05'));

		$this->assertSame([$this->columns()], $this->written);
	}//end testAnExistingRowIsUpdatedInPlace()

	public function testAFileWithoutARowGetsOneInserted(): void {
		$this->store([$this->builder(0), $this->builder(1)])->save(42, ['pieter', '1001'], ['woo-team'], 'Prices', 'pieter', new DateTimeImmutable('2026-01-02 03:04:05'));

		$this->assertSame([$this->columns(), ['file_id' => 42] + $this->columns()], $this->written);
	}//end testAFileWithoutARowGetsOneInserted()
}//end class
