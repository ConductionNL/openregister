<?php

/**
 * Golden test pinning the exact reports MigrationService returns.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\MigrationService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Pins the whole status and stub-report arrays, key order included.
 */
class MigrationServiceGoldenTest extends TestCase {

	/**
	 * @var MagicMapper&MockObject
	 */
	private MagicMapper&MockObject $magicMapper;

	/**
	 * @var MigrationService
	 */
	private MigrationService $service;

	protected function setUp(): void {
		$this->magicMapper = $this->createMock(MagicMapper::class);
		$this->service     = new MigrationService(
			$this->magicMapper,
			$this->createMock(RegisterMapper::class),
			$this->createMock(SchemaMapper::class)
		);
	}

	/**
	 * Build an entity with a forced id.
	 *
	 * @param Register|Schema $entity The entity.
	 * @param int             $id     The id.
	 * @param string          $title  The title.
	 * @param string          $slug   The slug.
	 *
	 * @return Register|Schema
	 */
	private function entity(Register|Schema $entity, int $id, string $title, string $slug): Register|Schema {
		$entity->setTitle($title);
		$entity->setSlug($slug);
		$prop = new \ReflectionProperty($entity, 'id');
		$prop->setAccessible(true);
		$prop->setValue($entity, $id);
		return $entity;
	}

	public function testStatusWithMagicTableIsPinned(): void {
		$register = $this->entity(new Register(), 3, 'Reg', 'reg');
		$schema   = $this->entity(new Schema(), 8, 'Sch', 'sch');
		$this->magicMapper->method('existsTableForRegisterSchema')->willReturn(true);
		$this->magicMapper->expects($this->once())
			->method('countObjectsInRegisterSchemaTable')
			->with([], $register, $schema)
			->willReturn(42);

		$this->assertSame(
			[
				'register' => ['id' => 3, 'name' => 'Reg', 'slug' => 'reg'],
				'schema' => ['id' => 8, 'name' => 'Sch', 'slug' => 'sch'],
				'magicTable' => ['exists' => true, 'count' => 42],
			],
			$this->service->getStorageStatus($register, $schema)
		);
	}

	public function testStatusWithoutMagicTableDoesNotCount(): void {
		$register = $this->entity(new Register(), 1, 'R', 'r');
		$schema   = $this->entity(new Schema(), 2, 'S', 's');
		$this->magicMapper->method('existsTableForRegisterSchema')->willReturn(false);
		$this->magicMapper->expects($this->never())->method('countObjectsInRegisterSchemaTable');

		$this->assertSame(
			[
				'register' => ['id' => 1, 'name' => 'R', 'slug' => 'r'],
				'schema' => ['id' => 2, 'name' => 'S', 'slug' => 's'],
				'magicTable' => ['exists' => false, 'count' => 0],
			],
			$this->service->getStorageStatus($register, $schema)
		);
	}

	public function testMigrationStubReportsArePinned(): void {
		$register = new Register();
		$schema   = new Schema();
		$this->assertSame(
			[
				'direction' => 'to-magic',
				'dryRun' => true,
				'total' => 0,
				'migrated' => 0,
				'skipped' => 0,
				'failed' => 0,
				'errors' => [],
				'message' => 'Blob storage mapper has been removed. Use BlobMigrationJob background job instead.',
			],
			$this->service->migrateToMagicTable($register, $schema, 10, true)
		);
		$this->assertSame(
			[
				'direction' => 'to-blob',
				'dryRun' => false,
				'total' => 0,
				'migrated' => 0,
				'skipped' => 0,
				'failed' => 0,
				'errors' => [],
				'message' => 'Blob storage mapper has been removed. Reverse migration is no longer supported.',
			],
			$this->service->migrateToBlobStorage($register, $schema)
		);
	}
}
