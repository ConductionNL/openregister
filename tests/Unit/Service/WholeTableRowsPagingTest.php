<?php

/**
 * WholeTableRows: reading a register/schema table in 500-row pages, counting the
 * rows a teardown must keep, and refusing a whole-table write on a kept row.
 *
 * The row hold (FOR UPDATE, per platform) is pinned in WholeTableDeleteHoldsRowsTest;
 * this file covers paging, the missing-table short cut and the per-state decisions.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
// phpcs:disable Squiz.Commenting.VariableComment.Missing -- typed mock fixtures; the declaration IS the description.

use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\LockedException;
use OCA\OpenRegister\Exception\ObjectStateWriteException;
use OCA\OpenRegister\Service\WholeTableRows;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\WholeTableRows
 */
class WholeTableRowsPagingTest extends TestCase {

	private MagicMapperInterface&MockObject $mapper;
	private IDBConnection&MockObject $db;
	private Register $register;
	private Schema $schema;

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = $this->createMock(MagicMapperInterface::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->db->method('getDatabaseProvider')->willReturn(IDBConnection::PLATFORM_SQLITE);
		$this->register = new Register();
		$this->schema = new Schema();
	}

	private function rows(): WholeTableRows {
		return new WholeTableRows($this->mapper, $this->db);
	}

	/**
	 * Objects, each optionally in one kept state.
	 *
	 * @param int $count How many.
	 * @param string|null $state 'archived', 'frozen', 'locked' or null.
	 *
	 * @return array<int, ObjectEntity>
	 */
	private static function objects(int $count, ?string $state = null): array {
		$objects = [];
		for ($i = 0; $i < $count; $i++) {
			$object = new ObjectEntity();
			$object->setUuid("u{$i}");
			match ($state) {
				'archived' => $object->setArchived(['at' => '2026-01-01T00:00:00+00:00']),
				'frozen' => $object->setFrozen(['at' => '2026-01-01T00:00:00+00:00']),
				'locked' => $object->setLocked(['user' => 'alice']),
				default => null,
			};
			$objects[] = $object;
		}

		return $objects;
	}

	public function testAllReadsPagesOf500UntilAShortPage(): void {
		$offsets = [];
		$pages = [self::objects(500), self::objects(500), self::objects(3)];
		$this->mapper->method('findAllInRegisterSchemaTable')->willReturnCallback(
			function (RegisterSchema $table, ListQuery $listing) use (&$offsets, &$pages): array {
				[$register, $schema, $limit, $offset, $filters] = [$table->register, $table->schema, $listing->limitOrNull(), $listing->offset, ($listing->filters === [] ? null : $listing->filters)];
				$offsets[] = [$limit, $offset];
				$this->assertSame(['_includeDeleted' => true, '_rbac' => false, '_multitenancy' => false], $filters);
				return array_shift($pages);
			}
		);

		$all = iterator_to_array($this->rows()->all($this->register, $this->schema), false);

		$this->assertCount(1003, $all);
		$this->assertSame([[500, 0], [500, 500], [500, 1000]], $offsets);
	}

	public function testAnExactlyFullLastPageCostsOneMoreEmptyRead(): void {
		$this->mapper->expects($this->exactly(2))->method('findAllInRegisterSchemaTable')
			->willReturnOnConsecutiveCalls(self::objects(500), []);

		$this->assertCount(500, iterator_to_array($this->rows()->all($this->register, $this->schema), false));
	}

	public function testAMissingTableKeepsNothingAndIsNeverRead(): void {
		$this->mapper->method('tableExistsForRegisterSchema')->willReturn(false);
		$this->mapper->expects($this->never())->method('findAllInRegisterSchemaTable');
		$this->db->expects($this->never())->method('executeQuery');

		$this->assertSame(0, $this->rows()->countKept($this->register, $this->schema));
		$this->rows()->assertNoneKept($this->register, $this->schema);
	}

	public function testCountKeptCountsArchivedFrozenAndLockedRowsOnly(): void {
		$this->mapper->method('tableExistsForRegisterSchema')->willReturn(true);
		$this->mapper->method('findAllInRegisterSchemaTable')->willReturn(
			array_merge(self::objects(2), self::objects(1, 'archived'), self::objects(2, 'frozen'), self::objects(1, 'locked'))
		);

		$this->assertSame(4, $this->rows()->countKept($this->register, $this->schema));
	}

	public function testOrdinaryRowsPassTheGuard(): void {
		$this->mapper->method('tableExistsForRegisterSchema')->willReturn(true);
		$this->mapper->expects($this->once())->method('findAllInRegisterSchemaTable')->willReturn(self::objects(3));

		$this->rows()->assertNoneKept($this->register, $this->schema);
	}

	public function testALockedRowRefusesTheWholeTableWrite(): void {
		$this->mapper->method('tableExistsForRegisterSchema')->willReturn(true);
		$this->mapper->method('findAllInRegisterSchemaTable')->willReturn(array_merge(self::objects(1), self::objects(1, 'locked')));

		$this->expectException(LockedException::class);
		$this->rows()->assertNoneKept($this->register, $this->schema);
	}

	public function testAFrozenRowRefusesTheWholeTableWrite(): void {
		$this->mapper->method('tableExistsForRegisterSchema')->willReturn(true);
		$this->mapper->method('findAllInRegisterSchemaTable')->willReturn(self::objects(1, 'frozen'));

		$this->expectException(ObjectStateWriteException::class);
		$this->rows()->assertNoneKept($this->register, $this->schema);
	}
}
