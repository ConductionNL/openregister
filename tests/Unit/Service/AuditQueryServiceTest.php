<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Service\AuditQueryService}.
 *
 * Covers filtered query (registerId+schemaId), paging/clamping, the
 * naming-convention fallback when no explicit register/schema filter is
 * given, and the graceful "unconfigured audit schema" empty-result path.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/public-audit-query-endpoint/tasks.md#task-4.1
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\AuditQueryService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AuditQueryServiceTest extends TestCase {

	private ObjectService&MockObject $objectService;

	private RegisterMapper&MockObject $registerMapper;

	private SchemaMapper&MockObject $schemaMapper;

	private AuditQueryService $service;

	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectService::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);

		$this->service = new AuditQueryService(
			objectService: $this->objectService,
			registerMapper: $this->registerMapper,
			schemaMapper: $this->schemaMapper
		);

	}//end setUp()

	private function makeRegister(int $id, string $slug): Register {
		$register = new Register();
		$register->setId($id);
		$register->setSlug($slug);
		return $register;
	}//end makeRegister()

	private function makeSchema(int $id, string $slug, string $title = ''): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug($slug);
		$schema->setTitle($title);
		return $schema;
	}//end makeSchema()

	private function makeAuditEntry(string $uuid, array $data, ?\DateTime $created = null, string $owner = 'admin'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);
		$entity->setCreated($created ?? new \DateTime('2026-07-12T10:00:00+00:00'));
		$entity->setOwner($owner);
		return $entity;
	}//end makeAuditEntry()

	public function testQueryWithRegisterAndSchemaFiltersReturnsMappedEntries(): void {
		$register = $this->makeRegister(1, 'procest');
		$schema = $this->makeSchema(10, 'aiAuditEntry', 'AI Audit Entry');

		$this->registerMapper->method('find')->with('procest')->willReturn($register);
		$this->schemaMapper->method('find')->with('aiAuditEntry')->willReturn($schema);

		$entry = $this->makeAuditEntry(
			uuid: 'entry-uuid-1',
			data: ['objectId' => 'case-uuid', 'action' => 'rejected'],
		);

		$this->objectService->expects($this->once())
			->method('searchObjectsBySlug')
			->with(registerSlug: 'procest', schemaSlug: 'aiAuditEntry', filters: [])
			->willReturn([$entry]);

		$result = $this->service->query(
			filters: ['registerId' => 'procest', 'schemaId' => 'aiAuditEntry'],
			limit: 50,
			offset: 0
		);

		$this->assertSame(1, $result['total']);
		$this->assertCount(1, $result['entries']);
		$this->assertSame('entry-uuid-1', $result['entries'][0]['id']);
		$this->assertSame('procest', $result['entries'][0]['registerId']);
		$this->assertSame('aiAuditEntry', $result['entries'][0]['schemaId']);
		$this->assertSame('case-uuid', $result['entries'][0]['objectId']);
		// ObjectEntity::getObject() always merges its own uuid in as 'id'
		// (OR convention: the object's own id is the first field of its
		// rendered data), so the audit entry's data payload carries it too.
		$this->assertSame(
			['id' => 'entry-uuid-1', 'objectId' => 'case-uuid', 'action' => 'rejected'],
			$result['entries'][0]['data']
		);
		$this->assertSame('admin', $result['entries'][0]['userId']);

	}//end testQueryWithRegisterAndSchemaFiltersReturnsMappedEntries()

	public function testQueryPassesObjectIdAsObjectFieldFilter(): void {
		$register = $this->makeRegister(1, 'procest');
		$schema = $this->makeSchema(10, 'aiAuditEntry');

		$this->registerMapper->method('find')->willReturn($register);
		$this->schemaMapper->method('find')->willReturn($schema);

		$this->objectService->expects($this->once())
			->method('searchObjectsBySlug')
			->with(registerSlug: 'procest', schemaSlug: 'aiAuditEntry', filters: ['objectId' => 'case-uuid'])
			->willReturn([]);

		$this->service->query(
			filters: ['registerId' => 'procest', 'schemaId' => 'aiAuditEntry', 'objectId' => 'case-uuid'],
			limit: 50,
			offset: 0
		);

	}//end testQueryPassesObjectIdAsObjectFieldFilter()

	public function testQueryClampsLimitAboveMaximum(): void {
		$register = $this->makeRegister(1, 'procest');
		$schema = $this->makeSchema(10, 'aiAuditEntry');

		$this->registerMapper->method('find')->willReturn($register);
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->objectService->method('searchObjectsBySlug')->willReturn([]);

		$result = $this->service->query(
			filters: ['registerId' => 'procest', 'schemaId' => 'aiAuditEntry'],
			limit: 5000,
			offset: 0
		);

		$this->assertSame(200, $result['limit']);

	}//end testQueryClampsLimitAboveMaximum()

	public function testQueryClampsLimitBelowMinimumToDefault(): void {
		$register = $this->makeRegister(1, 'procest');
		$schema = $this->makeSchema(10, 'aiAuditEntry');

		$this->registerMapper->method('find')->willReturn($register);
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->objectService->method('searchObjectsBySlug')->willReturn([]);

		$result = $this->service->query(
			filters: ['registerId' => 'procest', 'schemaId' => 'aiAuditEntry'],
			limit: 0,
			offset: 0
		);

		$this->assertSame(50, $result['limit']);

	}//end testQueryClampsLimitBelowMinimumToDefault()

	public function testQueryClampsNegativeOffsetToZero(): void {
		$register = $this->makeRegister(1, 'procest');
		$schema = $this->makeSchema(10, 'aiAuditEntry');

		$this->registerMapper->method('find')->willReturn($register);
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->objectService->method('searchObjectsBySlug')->willReturn([]);

		$result = $this->service->query(
			filters: ['registerId' => 'procest', 'schemaId' => 'aiAuditEntry'],
			limit: 50,
			offset: -10
		);

		$this->assertSame(0, $result['offset']);

	}//end testQueryClampsNegativeOffsetToZero()

	public function testQueryPagesAcrossResults(): void {
		$register = $this->makeRegister(1, 'procest');
		$schema = $this->makeSchema(10, 'aiAuditEntry');

		$this->registerMapper->method('find')->willReturn($register);
		$this->schemaMapper->method('find')->willReturn($schema);

		$entries = [
			$this->makeAuditEntry('e1', ['objectId' => 'c1'], new \DateTime('2026-07-10T10:00:00+00:00')),
			$this->makeAuditEntry('e2', ['objectId' => 'c2'], new \DateTime('2026-07-11T10:00:00+00:00')),
			$this->makeAuditEntry('e3', ['objectId' => 'c3'], new \DateTime('2026-07-12T10:00:00+00:00')),
		];
		$this->objectService->method('searchObjectsBySlug')->willReturn($entries);

		$result = $this->service->query(
			filters: ['registerId' => 'procest', 'schemaId' => 'aiAuditEntry'],
			limit: 1,
			offset: 1
		);

		$this->assertSame(3, $result['total']);
		$this->assertCount(1, $result['entries']);
		// Default sort is created:desc, so offset 1 of [e3, e2, e1] is e2.
		$this->assertSame('e2', $result['entries'][0]['id']);

	}//end testQueryPagesAcrossResults()

	public function testQueryFiltersByTimestampRange(): void {
		$register = $this->makeRegister(1, 'procest');
		$schema = $this->makeSchema(10, 'aiAuditEntry');

		$this->registerMapper->method('find')->willReturn($register);
		$this->schemaMapper->method('find')->willReturn($schema);

		$entries = [
			$this->makeAuditEntry('too-early', ['objectId' => 'c1'], new \DateTime('2026-01-01T00:00:00+00:00')),
			$this->makeAuditEntry('in-range', ['objectId' => 'c2'], new \DateTime('2026-07-12T00:00:00+00:00')),
			$this->makeAuditEntry('too-late', ['objectId' => 'c3'], new \DateTime('2026-12-31T00:00:00+00:00')),
		];
		$this->objectService->method('searchObjectsBySlug')->willReturn($entries);

		$result = $this->service->query(
			filters: [
				'registerId' => 'procest',
				'schemaId' => 'aiAuditEntry',
				'timestampStart' => '2026-07-01T00:00:00+00:00',
				'timestampEnd' => '2026-07-31T00:00:00+00:00',
			],
			limit: 50,
			offset: 0
		);

		$this->assertSame(1, $result['total']);
		$this->assertSame('in-range', $result['entries'][0]['id']);

	}//end testQueryFiltersByTimestampRange()

	public function testQueryFallsBackToAuditNamingConventionWithoutFilters(): void {
		$register = $this->makeRegister(1, 'procest');
		$auditSchema = $this->makeSchema(10, 'aiAuditEntry', 'AI Audit Entry');
		$businessSchema = $this->makeSchema(11, 'case', 'Case');

		$this->registerMapper->method('findAll')->willReturn([$register]);
		$this->registerMapper->method('getSchemasByRegisterId')
			->with(registerId: 1)
			->willReturn([$auditSchema, $businessSchema]);

		$entry = $this->makeAuditEntry('entry-1', ['objectId' => 'case-uuid']);

		// Only the audit-named schema should be searched — the business
		// schema must never be queried (query isolation: no default
		// exposure of every object type).
		$this->objectService->expects($this->once())
			->method('searchObjectsBySlug')
			->with(registerSlug: 'procest', schemaSlug: 'aiAuditEntry', filters: [])
			->willReturn([$entry]);

		$result = $this->service->query(filters: [], limit: 50, offset: 0);

		$this->assertSame(1, $result['total']);
		$this->assertSame('entry-1', $result['entries'][0]['id']);

	}//end testQueryFallsBackToAuditNamingConventionWithoutFilters()

	public function testQueryReturnsEmptyGracefullyWhenNoAuditSchemaConfigured(): void {
		$register = $this->makeRegister(1, 'procest');
		$onlyBusiness = $this->makeSchema(11, 'case', 'Case');

		$this->registerMapper->method('findAll')->willReturn([$register]);
		$this->registerMapper->method('getSchemasByRegisterId')->willReturn([$onlyBusiness]);

		$this->objectService->expects($this->never())->method('searchObjectsBySlug');

		$result = $this->service->query(filters: [], limit: 50, offset: 0);

		$this->assertSame(0, $result['total']);
		$this->assertSame([], $result['entries']);
		$this->assertSame(50, $result['limit']);
		$this->assertSame(0, $result['offset']);

	}//end testQueryReturnsEmptyGracefullyWhenNoAuditSchemaConfigured()

	public function testQueryReturnsEmptyGracefullyWhenRegisterFilterDoesNotResolve(): void {
		$this->registerMapper->method('find')->willThrowException(new DoesNotExistException('nope'));

		$this->objectService->expects($this->never())->method('searchObjectsBySlug');

		$result = $this->service->query(filters: ['registerId' => 'does-not-exist'], limit: 50, offset: 0);

		$this->assertSame(0, $result['total']);
		$this->assertSame([], $result['entries']);

	}//end testQueryReturnsEmptyGracefullyWhenRegisterFilterDoesNotResolve()

	public function testQueryReturnsEmptyGracefullyWhenSchemaFilterDoesNotResolve(): void {
		$this->schemaMapper->method('find')->willThrowException(new DoesNotExistException('nope'));

		$this->objectService->expects($this->never())->method('searchObjectsBySlug');

		$result = $this->service->query(filters: ['schemaId' => 'does-not-exist'], limit: 50, offset: 0);

		$this->assertSame(0, $result['total']);
		$this->assertSame([], $result['entries']);

	}//end testQueryReturnsEmptyGracefullyWhenSchemaFilterDoesNotResolve()

	public function testQueryUsesAppAsAliasForRegisterId(): void {
		$register = $this->makeRegister(1, 'procest');
		$schema = $this->makeSchema(10, 'aiAuditEntry', 'AI Audit Entry');

		$this->registerMapper->expects($this->once())->method('find')->with('procest')->willReturn($register);
		$this->registerMapper->method('getSchemasByRegisterId')->willReturn([$schema]);

		$this->objectService->method('searchObjectsBySlug')->willReturn([]);

		$this->service->query(filters: ['app' => 'procest'], limit: 50, offset: 0);

	}//end testQueryUsesAppAsAliasForRegisterId()
}//end class
