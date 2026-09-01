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

		$this->objectEntityMapper->method('findAcrossAllSources')->willReturn(['object' => $object]);
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

		$this->objectEntityMapper->method('findAcrossAllSources')->willReturn(['object' => $object]);
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
		$this->registerMapper->method('find')->willThrowException(new \Exception('Not found'));

		$this->auditTrailMapper->expects($this->once())
			->method('findAll')
			->willReturn(['log1']);

		$result = $this->service->getLogs('deleted-reg', 'deleted-schema', '42');

		$this->assertSame(['log1'], $result);
	}

	public function testCountReturnsLogCount(): void {
		$object = $this->createObjectEntity(42, '1', '2');
		$register = $this->createRegister(1);
		$schema = $this->createSchema(2);

		$this->objectEntityMapper->method('findAcrossAllSources')->willReturn(['object' => $object]);
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

		$this->objectEntityMapper->method('findAcrossAllSources')->willReturn(['object' => $object]);
		$this->registerMapper->method('find')->willReturn($register);
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->auditTrailMapper->method('findAll')->willReturn([]);

		$result = $this->service->count('1', '2', '42');

		$this->assertSame(0, $result);
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
