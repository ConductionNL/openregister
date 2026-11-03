<?php

namespace Unit\Service;

use Exception;
use InvalidArgumentException;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\LogService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class LogServiceTest extends TestCase {
	private AuditTrailMapper&MockObject $auditTrailMapper;
	private MagicMapper&MockObject $objectEntityMapper;
	private RegisterMapper&MockObject $registerMapper;
	private SchemaMapper&MockObject $schemaMapper;
	private LogService $service;

	protected function setUp(): void {
		$this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
		$this->objectEntityMapper = $this->createMock(MagicMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);

		$this->service = new LogService(
			$this->auditTrailMapper,
			$this->objectEntityMapper,
			$this->registerMapper,
			$this->schemaMapper
		);
	}

	private function createObjectEntity(int $id, string $register, string $schema): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setRegister($register);
		$entity->setSchema($schema);
		$ref = new \ReflectionClass($entity);
		$prop = $ref->getProperty('id');
		$prop->setAccessible(true);
		$prop->setValue($entity, $id);
		return $entity;
	}

	private function createRegister(int $id): Register {
		$register = new Register();
		$ref = new \ReflectionClass($register);
		$prop = $ref->getProperty('id');
		$prop->setAccessible(true);
		$prop->setValue($register, $id);
		return $register;
	}

	private function createSchema(int $id): Schema {
		$schema = new Schema();
		$ref = new \ReflectionClass($schema);
		$prop = $ref->getProperty('id');
		$prop->setAccessible(true);
		$prop->setValue($schema, $id);
		return $schema;
	}

	public function testGetLogsReturnsAuditTrails(): void {
		$object = $this->createObjectEntity(42, '1', '2');
		$register = $this->createRegister(1);
		$schema = $this->createSchema(2);

		$this->objectEntityMapper->method('findInRegisterSchemaTable')->willReturn($object);
		$this->registerMapper->method('find')->willReturn($register);
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->auditTrailMapper->method('findAll')->willReturn(['log1', 'log2']);

		$result = $this->service->getLogs('1', '2', '42');

		$this->assertSame(['log1', 'log2'], $result);
	}

	public function testGetLogsWithConfig(): void {
		$object = $this->createObjectEntity(42, '1', '2');
		$register = $this->createRegister(1);
		$schema = $this->createSchema(2);

		$this->objectEntityMapper->method('findInRegisterSchemaTable')->willReturn($object);
		$this->registerMapper->method('find')->willReturn($register);
		$this->schemaMapper->method('find')->willReturn($schema);

		$this->auditTrailMapper->expects($this->once())
			->method('findAll')
			->willReturn([]);

		$result = $this->service->getLogs('1', '2', '42', [
			'limit' => 10,
			'offset' => 5,
			'sort' => ['created' => 'ASC'],
			'search' => 'test',
		]);

		$this->assertSame([], $result);
	}

	public function testGetLogsAllowsAccessWhenRegisterSchemaDeleted(): void {
		$object = $this->createObjectEntity(42, '1', '2');

		$this->objectEntityMapper->method('findAcrossAllSources')->willReturn(['object' => $object]);
		$this->registerMapper->method('find')->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Not found'));

		$this->auditTrailMapper->expects($this->once())
			->method('findAll')
			->willReturn(['log1']);

		$result = $this->service->getLogs('deleted-reg', 'deleted-schema', '42');

		$this->assertSame(['log1'], $result);
	}

	public function testGetLogsKeepsTheScopeWhenTheRegisterLookupFailsOtherwise(): void {
		// Only a register or schema that is gone drops the scope; another failure must
		// not fall back to the cross-table lookup, where another schema's slug could win.
		$this->registerMapper->method('find')->willThrowException(new \RuntimeException('database gone'));
		$this->objectEntityMapper->expects($this->never())->method('findAcrossAllSources');

		$this->expectException(\RuntimeException::class);
		$this->service->getLogs('1', '2', 'hello-world');
	}

	public function testGetLogsLooksTheObjectUpWithinTheRegisterAndSchema(): void {
		// #4037: a slug is only unique within register + schema, so the history must be
		// looked up there and not across every table (the first hit could be another schema's).
		$object = $this->createObjectEntity(42, '1', '2');
		$register = $this->createRegister(1);
		$schema = $this->createSchema(2);
		$this->registerMapper->method('find')->willReturn($register);
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->objectEntityMapper->expects($this->never())->method('findAcrossAllSources');
		$this->objectEntityMapper->expects($this->once())
			->method('findInRegisterSchemaTable')
			->with('hello-world', $register, $schema, false, false, true)
			->willReturn($object);
		$this->auditTrailMapper->method('findAll')->willReturn(['log1']);

		$this->assertSame(['log1'], $this->service->getLogs('1', '2', 'hello-world'));
	}

	public function testGetLogsRefusesAnObjectOfAnotherSchemaAsNotFound(): void {
		$this->registerMapper->method('find')->willReturn($this->createRegister(1));
		$this->schemaMapper->method('find')->willReturn($this->createSchema(2));
		$this->objectEntityMapper->method('findInRegisterSchemaTable')
			->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('not in this register and schema'));
		$this->auditTrailMapper->expects($this->never())->method('findAll');

		$this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);
		$this->service->getLogs('1', '2', 'hello-world');
	}

	public function testGetLogsPropagatesAnAmbiguousSlug(): void {
		$this->registerMapper->method('find')->willReturn($this->createRegister(1));
		$this->schemaMapper->method('find')->willReturn($this->createSchema(2));
		$this->objectEntityMapper->method('findInRegisterSchemaTable')
			->willThrowException(new \OCP\AppFramework\Db\MultipleObjectsReturnedException('two objects hold "dubbel"'));
		$this->auditTrailMapper->expects($this->never())->method('findAll');

		$this->expectException(\OCP\AppFramework\Db\MultipleObjectsReturnedException::class);
		$this->service->count('1', '2', 'dubbel');
	}

	public function testGetLogsPrefersTheLiveHolderOverADeletedOneWithTheSameSlug(): void {
		// Review finding (Robert, 30-10): a slug freed by a delete goes to the next
		// object, so a deleted and a live object can share it; the live one is meant.
		$live = $this->createObjectEntity(43, '1', '2');
		$register = $this->createRegister(1);
		$schema = $this->createSchema(2);
		$this->registerMapper->method('find')->willReturn($register);
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->objectEntityMapper->method('findInRegisterSchemaTable')->willReturnCallback(
			function (string $id, $reg, $sch, bool $rbac, bool $multi, bool $includeDeleted) use ($live) {
				if ($includeDeleted === true) {
					throw new \OCP\AppFramework\Db\MultipleObjectsReturnedException('a deleted and a live object hold "dubbel"');
				}

				return $live;
			}
		);
		$this->auditTrailMapper->method('findAll')->willReturn(['log-live']);

		$this->assertSame(['log-live'], $this->service->getLogs('1', '2', 'dubbel'));
	}

	public function testGetLogsStaysAmbiguousWhenNoHolderIsLive(): void {
		$this->registerMapper->method('find')->willReturn($this->createRegister(1));
		$this->schemaMapper->method('find')->willReturn($this->createSchema(2));
		$this->objectEntityMapper->method('findInRegisterSchemaTable')->willReturnCallback(
			function (string $id, $reg, $sch, bool $rbac, bool $multi, bool $includeDeleted) {
				if ($includeDeleted === true) {
					throw new \OCP\AppFramework\Db\MultipleObjectsReturnedException('two deleted objects hold "dubbel"');
				}

				throw new \OCP\AppFramework\Db\DoesNotExistException('no live holder');
			}
		);
		$this->auditTrailMapper->expects($this->never())->method('findAll');

		$this->expectException(\OCP\AppFramework\Db\MultipleObjectsReturnedException::class);
		$this->service->getLogs('1', '2', 'dubbel');
	}

	public function testCountReturnsLogCount(): void {
		$object = $this->createObjectEntity(42, '1', '2');
		$register = $this->createRegister(1);
		$schema = $this->createSchema(2);

		$this->objectEntityMapper->method('findInRegisterSchemaTable')->willReturn($object);
		$this->registerMapper->method('find')->willReturn($register);
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->auditTrailMapper->method('findAll')->willReturn(['a', 'b', 'c']);

		$result = $this->service->count('1', '2', '42');

		$this->assertSame(3, $result);
	}

	public function testCountReturnsZeroWhenNoLogs(): void {
		$object = $this->createObjectEntity(42, '1', '2');
		$register = $this->createRegister(1);
		$schema = $this->createSchema(2);

		$this->objectEntityMapper->method('findInRegisterSchemaTable')->willReturn($object);
		$this->registerMapper->method('find')->willReturn($register);
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->auditTrailMapper->method('findAll')->willReturn([]);

		$result = $this->service->count('1', '2', '42');

		$this->assertSame(0, $result);
	}

	/**
	 * A count taken without the caller's filters counts a different result set.
	 *
	 * The audit-trail endpoint asked for the page WITH the filters and the
	 * total WITHOUT them, so `?action=update` on an object holding 1733 rows
	 * returned 7 of them and reported a total of 1733 — 87 pages of nothing for
	 * anything that pages on the number.
	 */
	public function testCountCountsUnderTheCallersFilters(): void {
		$object = $this->createObjectEntity(42, '1', '2');
		$object->setUuid('uuid-42');
		$register = $this->createRegister(1);
		$schema = $this->createSchema(2);

		$this->objectEntityMapper->method('findInRegisterSchemaTable')->willReturn($object);
		$this->registerMapper->method('find')->willReturn($register);
		$this->schemaMapper->method('find')->willReturn($schema);

		$seenFilters = null;
		$seenSearch = 'not-called';
		$this->auditTrailMapper->expects($this->once())
			->method('findAll')
			->willReturnCallback(
				function ($limit = null, $offset = null, $filters = [], $sort = null, $search = null) use (&$seenFilters, &$seenSearch) {
					$seenFilters = $filters;
					$seenSearch = $search;
					return ['a', 'b'];
				}
			);

		$result = $this->service->count(
			'1',
			'2',
			'42',
			['filters' => ['action' => 'update'], 'search' => 'needle']
		);

		$this->assertSame(2, $result);
		$this->assertSame(
			['action' => 'update', 'object_uuid' => 'uuid-42'],
			$seenFilters,
			'the count must narrow by the same filters the page was fetched with'
		);
		$this->assertSame('needle', $seenSearch);
	}

	public function testGetAllLogsWithDefaults(): void {
		$this->auditTrailMapper->expects($this->once())
			->method('findAll')
			->willReturn(['log1']);

		$result = $this->service->getAllLogs();

		$this->assertSame(['log1'], $result);
	}

	public function testGetAllLogsWithConfig(): void {
		$this->auditTrailMapper->expects($this->once())
			->method('findAll')
			->willReturn([]);

		$result = $this->service->getAllLogs([
			'limit' => 5,
			'offset' => 10,
			'filters' => ['action' => 'create'],
			'sort' => ['created' => 'ASC'],
			'search' => 'keyword',
		]);

		$this->assertSame([], $result);
	}

	public function testCountAllLogs(): void {
		$this->auditTrailMapper->method('findAll')->willReturn(['a', 'b']);

		$result = $this->service->countAllLogs();

		$this->assertSame(2, $result);
	}

	public function testCountAllLogsWithFilters(): void {
		$this->auditTrailMapper->method('findAll')->willReturn([]);

		$result = $this->service->countAllLogs(['action' => 'delete']);

		$this->assertSame(0, $result);
	}

	public function testGetLog(): void {
		$auditTrail = new AuditTrail();
		$this->auditTrailMapper->method('find')->willReturn($auditTrail);

		$result = $this->service->getLog(1);

		$this->assertInstanceOf(AuditTrail::class, $result);
	}

	public function testExportLogsJson(): void {
		$mockLog = $this->createMock(\JsonSerializable::class);
		$mockLog->method('jsonSerialize')->willReturn([
			'id' => 1,
			'uuid' => 'test-uuid',
			'action' => 'create',
			'object' => '42',
			'register' => '1',
			'schema' => '2',
			'user' => 'admin',
			'userName' => 'Admin',
			'created' => '2024-01-01',
			'size' => 100,
		]);

		$this->auditTrailMapper->method('findAll')->willReturn([$mockLog]);

		$result = $this->service->exportLogs('json');

		$this->assertSame('application/json', $result['contentType']);
		$this->assertStringContainsString('.json', $result['filename']);
		$this->assertNotEmpty($result['content']);
	}

	public function testExportLogsCsv(): void {
		$mockLog = $this->createMock(\JsonSerializable::class);
		$mockLog->method('jsonSerialize')->willReturn([
			'id' => 1,
			'uuid' => 'test-uuid',
			'action' => 'create',
			'object' => '42',
			'register' => '1',
			'schema' => '2',
			'user' => 'admin',
			'userName' => 'Admin',
			'created' => '2024-01-01',
			'size' => 100,
		]);

		$this->auditTrailMapper->method('findAll')->willReturn([$mockLog]);

		$result = $this->service->exportLogs('csv');

		$this->assertSame('text/csv', $result['contentType']);
		$this->assertStringContainsString('.csv', $result['filename']);
	}

	public function testExportLogsXml(): void {
		$mockLog = $this->createMock(\JsonSerializable::class);
		$mockLog->method('jsonSerialize')->willReturn([
			'id' => 1,
			'uuid' => 'test-uuid',
			'action' => 'create',
			'object' => '42',
			'register' => '1',
			'schema' => '2',
			'user' => 'admin',
			'userName' => 'Admin',
			'created' => '2024-01-01',
			'size' => 100,
		]);

		$this->auditTrailMapper->method('findAll')->willReturn([$mockLog]);

		$result = $this->service->exportLogs('xml');

		$this->assertSame('application/xml', $result['contentType']);
	}

	public function testExportLogsTxt(): void {
		$mockLog = $this->createMock(\JsonSerializable::class);
		$mockLog->method('jsonSerialize')->willReturn([
			'id' => 1,
			'uuid' => 'test-uuid',
			'action' => 'create',
			'object' => '42',
			'register' => '1',
			'schema' => '2',
			'user' => 'admin',
			'userName' => 'Admin',
			'created' => '2024-01-01',
			'size' => 100,
		]);

		$this->auditTrailMapper->method('findAll')->willReturn([$mockLog]);

		$result = $this->service->exportLogs('txt');

		$this->assertSame('text/plain', $result['contentType']);
		$this->assertStringContainsString('Audit Trail Export', $result['content']);
	}

	public function testExportLogsUnsupportedFormat(): void {
		$this->auditTrailMapper->method('findAll')->willReturn([]);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Unsupported export format');

		$this->service->exportLogs('pdf');
	}

	public function testExportLogsCsvEmpty(): void {
		$this->auditTrailMapper->method('findAll')->willReturn([]);

		$result = $this->service->exportLogs('csv');

		$this->assertSame('text/csv', $result['contentType']);
		$this->assertSame('', $result['content']);
	}
}
