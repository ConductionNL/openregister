<?php

declare(strict_types=1);

/**
 * ImportService Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 * @author   OpenRegister Team
 * @license  EUPL-1.2
 * @link     https://github.com/OpenRegister/OpenRegister
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Import\ImportValueCaster;
use OCA\OpenRegister\Service\Import\ImportTarget;
use OCA\OpenRegister\Service\Import\MappedSheet;
use OCA\OpenRegister\Service\Import\RowImportTarget;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Tests\Unit\Service\MigrationPack\PassThroughL10nTrait;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Unit tests for ImportService
 *
 * Tests import logic, data transformation, and caching.
 */
class ImportServiceTest extends TestCase {
	use PassThroughL10nTrait;

	/** @var MagicMapper&MockObject */
	private MagicMapper $objectMapper;

	/** @var SchemaMapper&MockObject */
	private SchemaMapper $schemaMapper;

	/** @var ObjectService&MockObject */
	private ObjectService $objectService;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	/** @var IGroupManager&MockObject */
	private IGroupManager $groupManager;

	/**
	 * A constraint that accepts any objects batch and keeps it in $saved.
	 *
	 * @param array|null $saved Receives the objects handed to saveObjects().
	 *
	 * @return \PHPUnit\Framework\Constraint\Callback The constraint.
	 */
	private function captureObjects(?array &$saved): \PHPUnit\Framework\Constraint\Callback {
		return $this->callback(
			function (array $objects) use (&$saved): bool {
				$saved = $objects;
				return true;
			}
		);
	}

	/** @var ImportService */
	private ImportService $service;

	protected function setUp(): void {
		$this->objectMapper = $this->createMock(MagicMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->groupManager = $this->createMock(IGroupManager::class);

		// The CSV codec is invoked as a translatable-property pre-pass
		// inside transformCsvRow; without a stub the default
		// mock returns null and wipes the row data. Pass-through.
		$translationCsvCodec = $this->createMock(\OCA\OpenRegister\Service\Translation\TranslationCsvCodec::class);
		$translationCsvCodec->method('unflattenFromCsv')
			->willReturnCallback(static fn (array $row) => $row);

		$this->service = new ImportService(
			new \OCA\OpenRegister\Service\Import\ImportServiceStores($this->schemaMapper, $this->objectService, $this->createMock(\OCA\OpenRegister\Db\AuditTrailMapper::class), $this->createMock(\OCA\OpenRegister\Service\Object\ValidateObject::class)),
			new \OCA\OpenRegister\Service\Import\ImportServiceCodecs($translationCsvCodec, new \OCA\OpenRegister\Service\MigrationPack\MappingEngine($this->passThroughL10n())),
			new \OCA\OpenRegister\Service\Import\ImportServiceRuntime($this->createMock(\Psr\Container\ContainerInterface::class), new \OCA\OpenRegister\Listener\NotifyPushBatch(), $this->logger),
			$this->groupManager
		);
	}

	private function createRegister(int $id): Register {
		$register = new Register();
		$register->setTitle('TestRegister');
		$ref = new ReflectionClass($register);
		$prop = $ref->getProperty('id');
		$prop->setAccessible(true);
		$prop->setValue($register, $id);
		return $register;
	}

	private function createSchema(int $id, array $properties = [], string $slug = 'test-schema'): Schema {
		$schema = new Schema();
		$schema->setTitle('TestSchema');
		$schema->setSlug($slug);
		$schema->setProperties($properties);
		$ref = new ReflectionClass($schema);
		$prop = $ref->getProperty('id');
		$prop->setAccessible(true);
		$prop->setValue($schema, $id);
		return $schema;
	}

	/**
	 * Create a temporary Excel file with the given data.
	 *
	 * @param array $headers Column headers
	 * @param array $rows Data rows (array of arrays)
	 * @param string $sheetTitle Sheet title (default: 'Sheet1')
	 *
	 * @return string Path to the temporary file
	 */
	private function createTempExcel(array $headers, array $rows, string $sheetTitle = 'Sheet1'): string {
		$spreadsheet = new Spreadsheet();
		$sheet = $spreadsheet->getActiveSheet();
		$sheet->setTitle($sheetTitle);

		// Write headers.
		foreach ($headers as $colIndex => $header) {
			$sheet->setCellValue(
				\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex + 1) . '1',
				$header
			);
		}

		// Write rows.
		foreach ($rows as $rowIndex => $row) {
			foreach ($row as $colIndex => $value) {
				$sheet->setCellValue(
					\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex + 1) . ($rowIndex + 2),
					$value
				);
			}
		}

		$tmpFile = tempnam(sys_get_temp_dir(), 'test_xlsx_') . '.xlsx';
		$writer = new Xlsx($spreadsheet);
		$writer->save($tmpFile);

		return $tmpFile;
	}

	/**
	 * Create a multi-sheet Excel file.
	 *
	 * @param array $sheets Array of [sheetTitle => [headers, rows]]
	 *
	 * @return string Path to the temporary file
	 */
	private function createMultiSheetExcel(array $sheets): string {
		$spreadsheet = new Spreadsheet();
		$firstSheet = true;

		foreach ($sheets as $title => $data) {
			if ($firstSheet) {
				$sheet = $spreadsheet->getActiveSheet();
				$firstSheet = false;
			} else {
				$sheet = $spreadsheet->createSheet();
			}
			$sheet->setTitle($title);

			$headers = $data['headers'];
			$rows = $data['rows'];

			foreach ($headers as $colIndex => $header) {
				$sheet->setCellValue(
					\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex + 1) . '1',
					$header
				);
			}

			foreach ($rows as $rowIndex => $row) {
				foreach ($row as $colIndex => $value) {
					$sheet->setCellValue(
						\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex + 1) . ($rowIndex + 2),
						$value
					);
				}
			}
		}

		$tmpFile = tempnam(sys_get_temp_dir(), 'test_xlsx_') . '.xlsx';
		$writer = new Xlsx($spreadsheet);
		$writer->save($tmpFile);

		return $tmpFile;
	}

	// =========================================================================
	// clearCaches
	// =========================================================================

	public function testClearCaches(): void {
		$cache = new \ReflectionProperty($this->service, 'schemaPropertiesCache');
		$cache->setValue($this->service, ['2' => ['name' => ['type' => 'string']]]);

		$this->service->clearCaches();

		$this->assertSame([], $cache->getValue($this->service));
	}

	// =========================================================================
	// importFromCsv
	// =========================================================================

	public function testImportFromCsvWithInvalidPath(): void {
		$this->expectException(\Exception::class);

		$this->service->importFromCsv('/nonexistent/path.csv');
	}

	public function testImportFromCsvRequiresSchema(): void {
		$tmpFile = tempnam(sys_get_temp_dir(), 'test_import_');
		file_put_contents($tmpFile, "name,email\nJohn,john@test.nl\n");

		try {
			$this->expectException(\InvalidArgumentException::class);
			$this->expectExceptionMessage('CSV import requires a specific schema');

			$register = $this->createRegister(1);
			$this->service->importFromCsv($tmpFile, $register, null);
		} finally {
			@unlink($tmpFile);
		}
	}

	public function testImportFromCsvWithValidFile(): void {
		$tmpFile = tempnam(sys_get_temp_dir(), 'test_import_');
		file_put_contents($tmpFile, "name,email\nJohn,john@test.nl\nJane,jane@test.nl\n");

		$register = $this->createRegister(1);
		$schema = $this->createSchema(2, [
			'name' => ['type' => 'string'],
			'email' => ['type' => 'string'],
		]);

		$this->objectService->method('saveObjects')
			->willReturn([
				'saved' => [
					['@self' => ['id' => 'uuid-1'], 'name' => 'John'],
					['@self' => ['id' => 'uuid-2'], 'name' => 'Jane'],
				],
				'updated' => [],
				'unchanged' => [],
			]);

		try {
			$result = $this->service->importFromCsv($tmpFile, $register, $schema);
			$this->assertIsArray($result);

			// Get the first (and only) sheet result.
			$sheetResult = reset($result);
			$this->assertSame(2, $sheetResult['found']);
			$this->assertCount(2, $sheetResult['created']);
		} finally {
			@unlink($tmpFile);
		}
	}

	public function testImportFromCsvWithPublishEnabled(): void {
		$tmpFile = tempnam(sys_get_temp_dir(), 'test_import_');
		file_put_contents($tmpFile, "name\nTest\n");

		$register = $this->createRegister(1);
		$schema = $this->createSchema(2, [
			'name' => ['type' => 'string'],
		]);

		$saved = null;
		$this->objectService->expects($this->once())
			->method('saveObjects')
			->with($this->captureObjects($saved))
			->willReturn([
				'saved' => [['@self' => ['id' => 'uuid-1']]],
				'updated' => [],
				'unchanged' => [],
			]);

		try {
			$result = $this->service->importFromCsv(
				$tmpFile,
				$register,
				$schema,
				publish: true
			);
			$this->assertIsArray($result);
			$this->assertSame([['name' => 'Test', '@self' => ['register' => 1, 'schema' => 2]]], $saved);
		} finally {
			@unlink($tmpFile);
		}
	}

	public function testImportFromCsvWithEmptyDataRows(): void {
		$tmpFile = tempnam(sys_get_temp_dir(), 'test_import_');
		// Only header, no data rows.
		file_put_contents($tmpFile, "name,email\n");

		$register = $this->createRegister(1);
		$schema = $this->createSchema(2, [
			'name' => ['type' => 'string'],
			'email' => ['type' => 'string'],
		]);

		try {
			$result = $this->service->importFromCsv($tmpFile, $register, $schema);
			$this->assertIsArray($result);

			$sheetResult = reset($result);
			$this->assertNotEmpty($sheetResult['errors']);
		} finally {
			@unlink($tmpFile);
		}
	}

	public function testImportFromCsvWithTypedProperties(): void {
		$tmpFile = tempnam(sys_get_temp_dir(), 'test_import_');
		file_put_contents($tmpFile, "name,age,active,tags\nJohn,30,true,\"a,b,c\"\n");

		$register = $this->createRegister(1);
		$schema = $this->createSchema(2, [
			'name' => ['type' => 'string'],
			'age' => ['type' => 'integer'],
			'active' => ['type' => 'boolean'],
			'tags' => ['type' => 'array'],
		]);

		$saved = null;
		$this->objectService->expects($this->once())
			->method('saveObjects')
			->with($this->captureObjects($saved))
			->willReturn([
				'saved' => [['@self' => ['id' => 'uuid-1']]],
				'updated' => [],
				'unchanged' => [],
			]);

		try {
			$result = $this->service->importFromCsv($tmpFile, $register, $schema);
			$this->assertIsArray($result);
			$this->assertSame([['name' => 'John', 'age' => 30, 'active' => true, 'tags' => ['a', 'b', 'c'], '@self' => ['register' => 1, 'schema' => 2]]], $saved);
		} finally {
			@unlink($tmpFile);
		}
	}

	public function testImportFromCsvWithUnchangedObjects(): void {
		$tmpFile = tempnam(sys_get_temp_dir(), 'test_import_');
		file_put_contents($tmpFile, "name\nJohn\nJane\n");

		$register = $this->createRegister(1);
		$schema = $this->createSchema(2, [
			'name' => ['type' => 'string'],
		]);

		$this->objectService->method('saveObjects')
			->willReturn([
				'saved' => [],
				'updated' => [],
				'unchanged' => [
					['@self' => ['id' => 'uuid-1']],
					['@self' => ['id' => 'uuid-2']],
				],
			]);

		try {
			$result = $this->service->importFromCsv($tmpFile, $register, $schema);
			$sheetResult = reset($result);
			$this->assertCount(2, $sheetResult['unchanged']);
			$this->assertStringContainsString('operations avoided', $sheetResult['deduplication_efficiency']);
		} finally {
			@unlink($tmpFile);
		}
	}

	public function testImportFromCsvWithUnderscoreColumnsSkipped(): void {
		$tmpFile = tempnam(sys_get_temp_dir(), 'test_import_');
		file_put_contents($tmpFile, "name,_internal\nJohn,hidden\n");

		$register = $this->createRegister(1);
		$schema = $this->createSchema(2, [
			'name' => ['type' => 'string'],
		]);

		$saved = null;
		$this->objectService->expects($this->once())
			->method('saveObjects')
			->with($this->captureObjects($saved))
			->willReturn([
				'saved' => [['@self' => ['id' => 'uuid-1']]],
				'updated' => [],
				'unchanged' => [],
			]);

		try {
			$result = $this->service->importFromCsv($tmpFile, $register, $schema);
			$this->assertIsArray($result);
			$this->assertSame([['name' => 'John', '@self' => ['register' => 1, 'schema' => 2]]], $saved);
		} finally {
			@unlink($tmpFile);
		}
	}

	public function testImportFromCsvWithAtColumnsSkippedForNonAdmin(): void {
		$tmpFile = tempnam(sys_get_temp_dir(), 'test_import_');
		file_put_contents($tmpFile, "name,@self.organisation\nJohn,org-uuid\n");

		$register = $this->createRegister(1);
		$schema = $this->createSchema(2, [
			'name' => ['type' => 'string'],
		]);

		// Non-admin user.
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$adminGroup = $this->createMock(IGroup::class);
		$adminGroup->method('inGroup')->willReturn(false);
		$this->groupManager->method('get')
			->with('admin')
			->willReturn($adminGroup);

		$saved = null;
		$this->objectService->expects($this->once())
			->method('saveObjects')
			->with($this->captureObjects($saved))
			->willReturn([
				'saved' => [['@self' => ['id' => 'uuid-1']]],
				'updated' => [],
				'unchanged' => [],
			]);

		try {
			$result = $this->service->importFromCsv(
				$tmpFile,
				$register,
				$schema,
				currentUser: $user
			);
			$this->assertIsArray($result);
			$this->assertSame([['name' => 'John', '@self' => ['register' => 1, 'schema' => 2]]], $saved);
		} finally {
			@unlink($tmpFile);
		}
	}

	public function testImportFromCsvWithAtColumnsProcessedForAdmin(): void {
		$tmpFile = tempnam(sys_get_temp_dir(), 'test_import_');
		file_put_contents($tmpFile, "name,@self.organisation\nJohn,12345678-1234-1234-1234-123456789abc\n");

		$register = $this->createRegister(1);
		$schema = $this->createSchema(2, [
			'name' => ['type' => 'string'],
		]);

		// Admin user.
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$adminGroup = $this->createMock(IGroup::class);
		$adminGroup->method('inGroup')->willReturn(true);
		$this->groupManager->method('get')
			->with('admin')
			->willReturn($adminGroup);

		$saved = null;
		$this->objectService->expects($this->once())
			->method('saveObjects')
			->with($this->captureObjects($saved))
			->willReturn([
				'saved' => [['@self' => ['id' => 'uuid-1']]],
				'updated' => [],
				'unchanged' => [],
			]);

		try {
			$result = $this->service->importFromCsv(
				$tmpFile,
				$register,
				$schema,
				currentUser: $user
			);
			$this->assertIsArray($result);
			$this->assertSame([['name' => 'John', '@self' => ['register' => 1, 'schema' => 2, 'organisation' => '12345678-1234-1234-1234-123456789abc']]], $saved);
		} finally {
			@unlink($tmpFile);
		}
	}

	public function testImportFromCsvWithValidationErrors(): void {
		$tmpFile = tempnam(sys_get_temp_dir(), 'test_import_');
		file_put_contents($tmpFile, "name\nJohn\n");

		$register = $this->createRegister(1);
		$schema = $this->createSchema(2, [
			'name' => ['type' => 'string'],
		]);

		$this->objectService->method('saveObjects')
			->willReturn([
				'saved' => [],
				'updated' => [],
				'unchanged' => [],
				'invalid' => [
					['object' => ['name' => 'John'], 'error' => 'Name too short', 'type' => 'ValidationException'],
				],
			]);

		try {
			$result = $this->service->importFromCsv(
				$tmpFile,
				$register,
				$schema,
				validation: true
			);
			$sheetResult = reset($result);
			$this->assertNotEmpty($sheetResult['errors']);
			$this->assertSame('Name too short', $sheetResult['errors'][0]['error']);
		} finally {
			@unlink($tmpFile);
		}
	}

	public function testImportFromCsvPerformanceMetrics(): void {
		$tmpFile = tempnam(sys_get_temp_dir(), 'test_import_');
		file_put_contents($tmpFile, "name\nJohn\n");

		$register = $this->createRegister(1);
		$schema = $this->createSchema(2, [
			'name' => ['type' => 'string'],
		]);

		$this->objectService->method('saveObjects')
			->willReturn([
				'saved' => [['@self' => ['id' => 'uuid-1']]],
				'updated' => [],
				'unchanged' => [],
			]);

		try {
			$result = $this->service->importFromCsv($tmpFile, $register, $schema);
			$sheetResult = reset($result);
			$this->assertArrayHasKey('performance', $sheetResult);
			$this->assertArrayHasKey('totalTime', $sheetResult['performance']);
			$this->assertArrayHasKey('objectsPerSecond', $sheetResult['performance']);
		} finally {
			@unlink($tmpFile);
		}
	}

	public function testImportFromCsvIncludesSchemaInfo(): void {
		$tmpFile = tempnam(sys_get_temp_dir(), 'test_import_');
		file_put_contents($tmpFile, "name\nJohn\n");

		$register = $this->createRegister(1);
		$schema = $this->createSchema(2, [
			'name' => ['type' => 'string'],
		]);

		$this->objectService->method('saveObjects')
			->willReturn([
				'saved' => [['@self' => ['id' => 'uuid-1']]],
				'updated' => [],
				'unchanged' => [],
			]);

		try {
			$result = $this->service->importFromCsv($tmpFile, $register, $schema);
			$sheetResult = reset($result);
			$this->assertArrayHasKey('schema', $sheetResult);
			$this->assertSame(2, $sheetResult['schema']['id']);
			$this->assertSame('TestSchema', $sheetResult['schema']['title']);
			$this->assertSame('test-schema', $sheetResult['schema']['slug']);
		} finally {
			@unlink($tmpFile);
		}
	}

	public function testImportFromCsvWithUpdatedObjects(): void {
		$tmpFile = tempnam(sys_get_temp_dir(), 'test_import_');
		file_put_contents($tmpFile, "name\nJohn\n");

		$register = $this->createRegister(1);
		$schema = $this->createSchema(2, [
			'name' => ['type' => 'string'],
		]);

		$this->objectService->method('saveObjects')
			->willReturn([
				'saved' => [],
				'updated' => [
					['@self' => ['id' => 'uuid-1']],
				],
				'unchanged' => [],
			]);

		try {
			$result = $this->service->importFromCsv($tmpFile, $register, $schema);
			$sheetResult = reset($result);
			$this->assertCount(1, $sheetResult['updated']);
			$this->assertSame('uuid-1', $sheetResult['updated'][0]);
		} finally {
			@unlink($tmpFile);
		}
	}

	public function testImportFromCsvWithPublishDisabled(): void {
		$tmpFile = tempnam(sys_get_temp_dir(), 'test_import_');
		file_put_contents($tmpFile, "name\nTest\n");

		$register = $this->createRegister(1);
		$schema = $this->createSchema(2, [
			'name' => ['type' => 'string'],
		]);

		$saved = null;
		$this->objectService->expects($this->once())
			->method('saveObjects')
			->with($this->captureObjects($saved))
			->willReturn([
				'saved' => [['@self' => ['id' => 'uuid-1']]],
				'updated' => [],
				'unchanged' => [],
			]);

		try {
			// Explicitly set publish to false to cover the "publish disabled" log branch.
			$result = $this->service->importFromCsv(
				$tmpFile,
				$register,
				$schema,
				publish: false
			);
			$this->assertIsArray($result);
			$this->assertSame([['name' => 'Test', '@self' => ['register' => 1, 'schema' => 2]]], $saved);
		} finally {
			@unlink($tmpFile);
		}
	}

	public function testImportFromCsvWithIdColumn(): void {
		$tmpFile = tempnam(sys_get_temp_dir(), 'test_import_');
		file_put_contents($tmpFile, "id,name\nuuid-existing,John\n");

		$register = $this->createRegister(1);
		$schema = $this->createSchema(2, [
			'name' => ['type' => 'string'],
		]);

		$saved = null;
		$this->objectService->expects($this->once())
			->method('saveObjects')
			->with($this->captureObjects($saved))
			->willReturn([
				'saved' => [],
				'updated' => [['@self' => ['id' => 'uuid-existing']]],
				'unchanged' => [],
			]);

		try {
			$result = $this->service->importFromCsv($tmpFile, $register, $schema);
			$this->assertIsArray($result);
			$this->assertSame([['id' => 'uuid-existing', 'name' => 'John', '@self' => ['register' => 1, 'schema' => 2, 'id' => 'uuid-existing']]], $saved);
		} finally {
			@unlink($tmpFile);
		}
	}

	public function testImportFromCsvWithAtColumnNotSelfPrefix(): void {
		// Test @ columns that don't start with @self. - they should be ignored.
		$tmpFile = tempnam(sys_get_temp_dir(), 'test_import_');
		file_put_contents($tmpFile, "name,@other.field\nJohn,some-value\n");

		$register = $this->createRegister(1);
		$schema = $this->createSchema(2, [
			'name' => ['type' => 'string'],
		]);

		// Admin user to ensure @ columns are processed.
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$adminGroup = $this->createMock(IGroup::class);
		$adminGroup->method('inGroup')->willReturn(true);
		$this->groupManager->method('get')
			->with('admin')
			->willReturn($adminGroup);

		$saved = null;
		$this->objectService->expects($this->once())
			->method('saveObjects')
			->with($this->captureObjects($saved))
			->willReturn([
				'saved' => [['@self' => ['id' => 'uuid-1']]],
				'updated' => [],
				'unchanged' => [],
			]);

		try {
			$result = $this->service->importFromCsv(
				$tmpFile,
				$register,
				$schema,
				currentUser: $user
			);
			$this->assertIsArray($result);
			$this->assertSame([['name' => 'John', '@self' => ['register' => 1, 'schema' => 2]]], $saved);
		} finally {
			@unlink($tmpFile);
		}
	}

	public function testImportFromCsvWithSelfPublishedColumnForAdmin(): void {
		$tmpFile = tempnam(sys_get_temp_dir(), 'test_import_');
		file_put_contents($tmpFile, "name,@self.published\nJohn,2025-01-01T00:00:00+00:00\n");

		$register = $this->createRegister(1);
		$schema = $this->createSchema(2, [
			'name' => ['type' => 'string'],
		]);

		// Admin user.
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$adminGroup = $this->createMock(IGroup::class);
		$adminGroup->method('inGroup')->willReturn(true);
		$this->groupManager->method('get')
			->with('admin')
			->willReturn($adminGroup);

		$saved = null;
		$this->objectService->expects($this->once())
			->method('saveObjects')
			->with($this->captureObjects($saved))
			->willReturn([
				'saved' => [['@self' => ['id' => 'uuid-1']]],
				'updated' => [],
				'unchanged' => [],
			]);

		try {
			$result = $this->service->importFromCsv(
				$tmpFile,
				$register,
				$schema,
				currentUser: $user
			);
			$this->assertIsArray($result);
			$this->assertSame([['name' => 'John', '@self' => ['register' => 1, 'schema' => 2, 'published' => '2025-01-01 00:00:00']]], $saved);
		} finally {
			@unlink($tmpFile);
		}
	}

	public function testImportFromCsvWithMultipleRowsAndMixedResults(): void {
		$tmpFile = tempnam(sys_get_temp_dir(), 'test_import_');
		file_put_contents($tmpFile, "name\nAlpha\nBeta\nGamma\n");

		$register = $this->createRegister(1);
		$schema = $this->createSchema(2, [
			'name' => ['type' => 'string'],
		]);

		$this->objectService->method('saveObjects')
			->willReturn([
				'saved' => [['@self' => ['id' => 'uuid-1']]],
				'updated' => [['@self' => ['id' => 'uuid-2']]],
				'unchanged' => [['@self' => ['id' => 'uuid-3']]],
			]);

		try {
			$result = $this->service->importFromCsv($tmpFile, $register, $schema);
			$sheetResult = reset($result);
			$this->assertSame(3, $sheetResult['found']);
			$this->assertCount(1, $sheetResult['created']);
			$this->assertCount(1, $sheetResult['updated']);
			$this->assertCount(1, $sheetResult['unchanged']);
			// 1 out of 3 unchanged = 33.3% efficiency.
			$this->assertStringContainsString('operations avoided', $sheetResult['deduplication_efficiency']);
		} finally {
			@unlink($tmpFile);
		}
	}

	public function testImportFromCsvWithNumberProperty(): void {
		$tmpFile = tempnam(sys_get_temp_dir(), 'test_import_');
		file_put_contents($tmpFile, "name,price\nItem,9.99\n");

		$register = $this->createRegister(1);
		$schema = $this->createSchema(2, [
			'name' => ['type' => 'string'],
			'price' => ['type' => 'number'],
		]);

		$saved = null;
		$this->objectService->expects($this->once())
			->method('saveObjects')
			->with($this->captureObjects($saved))
			->willReturn([
				'saved' => [['@self' => ['id' => 'uuid-1']]],
				'updated' => [],
				'unchanged' => [],
			]);

		try {
			$result = $this->service->importFromCsv($tmpFile, $register, $schema);
			$this->assertIsArray($result);
			$this->assertSame([['name' => 'Item', 'price' => 9.99, '@self' => ['register' => 1, 'schema' => 2]]], $saved);
		} finally {
			@unlink($tmpFile);
		}
	}

	public function testImportFromCsvWithObjectProperty(): void {
		$tmpFile = tempnam(sys_get_temp_dir(), 'test_import_');
		file_put_contents($tmpFile, "name,metadata\nItem,\"{\"\"key\"\":\"\"value\"\"}\"\n");

		$register = $this->createRegister(1);
		$schema = $this->createSchema(2, [
			'name' => ['type' => 'string'],
			'metadata' => ['type' => 'object'],
		]);

		$saved = null;
		$this->objectService->expects($this->once())
			->method('saveObjects')
			->with($this->captureObjects($saved))
			->willReturn([
				'saved' => [['@self' => ['id' => 'uuid-1']]],
				'updated' => [],
				'unchanged' => [],
			]);

		try {
			$result = $this->service->importFromCsv($tmpFile, $register, $schema);
			$this->assertIsArray($result);
			$this->assertSame([['name' => 'Item', 'metadata' => ['key' => 'value'], '@self' => ['register' => 1, 'schema' => 2]]], $saved);
		} finally {
			@unlink($tmpFile);
		}
	}

	public function testImportFromCsvWithRelatedObjectProperty(): void {
		$tmpFile = tempnam(sys_get_temp_dir(), 'test_import_');
		file_put_contents($tmpFile, "name,parent\nChild,parent-uuid\n");

		$register = $this->createRegister(1);
		$schema = $this->createSchema(2, [
			'name' => ['type' => 'string'],
			'parent' => [
				'type' => 'object',
				'objectConfiguration' => ['handling' => 'related-object'],
			],
		]);

		$saved = null;
		$this->objectService->expects($this->once())
			->method('saveObjects')
			->with($this->captureObjects($saved))
			->willReturn([
				'saved' => [['@self' => ['id' => 'uuid-1']]],
				'updated' => [],
				'unchanged' => [],
			]);

		try {
			$result = $this->service->importFromCsv($tmpFile, $register, $schema);
			$this->assertIsArray($result);
			$this->assertSame([['name' => 'Child', 'parent' => 'parent-uuid', '@self' => ['register' => 1, 'schema' => 2]]], $saved);
		} finally {
			@unlink($tmpFile);
		}
	}

	public function testImportFromCsvWithValidationErrorMissingFields(): void {
		$tmpFile = tempnam(sys_get_temp_dir(), 'test_import_');
		file_put_contents($tmpFile, "name\nJohn\n");

		$register = $this->createRegister(1);
		$schema = $this->createSchema(2, [
			'name' => ['type' => 'string'],
		]);

		// Return invalid items without explicit error/type fields to test defaults.
		$this->objectService->method('saveObjects')
			->willReturn([
				'saved' => [],
				'updated' => [],
				'unchanged' => [],
				'invalid' => [
					['object' => ['name' => 'John']],
				],
			]);

		try {
			$result = $this->service->importFromCsv(
				$tmpFile,
				$register,
				$schema,
				validation: true
			);
			$sheetResult = reset($result);
			$this->assertNotEmpty($sheetResult['errors']);
			$this->assertSame('Validation failed', $sheetResult['errors'][0]['error']);
			$this->assertSame('ValidationException', $sheetResult['errors'][0]['type']);
		} finally {
			@unlink($tmpFile);
		}
	}

	// =========================================================================
	// importFromExcel
	// =========================================================================

	public function testImportFromExcelWithInvalidPath(): void {
		$this->expectException(\Exception::class);

		$this->service->importFromExcel('/nonexistent/path.xlsx');
	}

	public function testImportFromExcelWithValidFile(): void {
		$tmpFile = $this->createTempExcel(
			['name', 'email'],
			[
				['John', 'john@test.nl'],
				['Jane', 'jane@test.nl'],
			]
		);

		$register = $this->createRegister(1);
		$schema = $this->createSchema(2, [
			'name' => ['type' => 'string'],
			'email' => ['type' => 'string'],
		]);

		$this->objectService->method('saveObjects')
			->willReturn([
				'saved' => [
					['@self' => ['id' => 'uuid-1']],
					['@self' => ['id' => 'uuid-2']],
				],
				'updated' => [],
				'unchanged' => [],
			]);

		try {
			$result = $this->service->importFromExcel($tmpFile, $register, $schema);
			$this->assertIsArray($result);
			$sheetResult = reset($result);
			$this->assertSame(2, $sheetResult['found']);
			$this->assertCount(2, $sheetResult['created']);
		} finally {
			@unlink($tmpFile);
		}
	}

	public function testImportFromExcelWithSchemaInfo(): void {
		$tmpFile = $this->createTempExcel(
			['name'],
			[['Test']]
		);

		$register = $this->createRegister(1);
		$schema = $this->createSchema(2, [
			'name' => ['type' => 'string'],
		]);

		$this->objectService->method('saveObjects')
			->willReturn([
				'saved' => [['@self' => ['id' => 'uuid-1']]],
				'updated' => [],
				'unchanged' => [],
			]);

		try {
			$result = $this->service->importFromExcel($tmpFile, $register, $schema);
			$sheetResult = reset($result);
			$this->assertArrayHasKey('schema', $sheetResult);
			$this->assertSame(2, $sheetResult['schema']['id']);
		} finally {
			@unlink($tmpFile);
		}
	}

	public function testImportFromExcelWithNoSchema(): void {
		$tmpFile = $this->createTempExcel(
			['name'],
			[['Test']]
		);

		$register = $this->createRegister(1);

		// Register provided but no schema - triggers processMultiSchemaSpreadsheetAsync.
		// getSchemaBySlug is called before the try block, so the exception propagates.
		$this->schemaMapper->method('find')
			->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Not found'));

		try {
			$this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);
			$this->service->importFromExcel($tmpFile, $register);
		} finally {
			@unlink($tmpFile);
		}
	}

	public function testImportFromExcelMultiSchemaWithMatchingSchema(): void {
		$tmpFile = $this->createMultiSheetExcel([
			'test-schema' => [
				'headers' => ['name', 'email'],
				'rows' => [['John', 'john@test.nl']],
			],
		]);

		$register = $this->createRegister(1);
		$schema = $this->createSchema(2, [
			'name' => ['type' => 'string'],
			'email' => ['type' => 'string'],
		]);

		// When no schema provided, it uses sheet titles to find schemas.
		$this->schemaMapper->method('find')
			->with('test-schema')
			->willReturn($schema);

		$this->objectService->method('saveObjects')
			->willReturn([
				'saved' => [['@self' => ['id' => 'uuid-1']]],
				'updated' => [],
				'unchanged' => [],
			]);

		try {
			$result = $this->service->importFromExcel($tmpFile, $register);
			$this->assertIsArray($result);
			$this->assertArrayHasKey('test-schema', $result);
			$this->assertSame(1, $result['test-schema']['found']);
		} finally {
			@unlink($tmpFile);
		}
	}

	public function testImportFromExcelWithPublishEnabled(): void {
		$tmpFile = $this->createTempExcel(
			['name'],
			[['Test']]
		);

		$register = $this->createRegister(1);
		$schema = $this->createSchema(2, [
			'name' => ['type' => 'string'],
		]);

		$saved = null;
		$this->objectService->expects($this->once())
			->method('saveObjects')
			->with($this->captureObjects($saved))
			->willReturn([
				'saved' => [['@self' => ['id' => 'uuid-1']]],
				'updated' => [],
				'unchanged' => [],
			]);

		try {
			$result = $this->service->importFromExcel(
				$tmpFile,
				$register,
				$schema,
				publish: true
			);
			$this->assertIsArray($result);
			$this->assertSame([['name' => 'Test', '@self' => ['register' => 1, 'schema' => 2]]], $saved);
		} finally {
			@unlink($tmpFile);
		}
	}

	public function testImportFromExcelWithEmptySheet(): void {
		$tmpFile = $this->createTempExcel(
			['name'],
			[] // No data rows.
		);

		$register = $this->createRegister(1);
		$schema = $this->createSchema(2, [
			'name' => ['type' => 'string'],
		]);

		try {
			$result = $this->service->importFromExcel($tmpFile, $register, $schema);
			$sheetResult = reset($result);
			$this->assertNotEmpty($sheetResult['errors']);
		} finally {
			@unlink($tmpFile);
		}
	}

	public function testImportFromExcelWithEmptyHeaders(): void {
		// Create an Excel file with no headers.
		$spreadsheet = new Spreadsheet();
		$sheet = $spreadsheet->getActiveSheet();
		// Write data in row 2 but no headers in row 1.
		$sheet->setCellValue('A2', 'value');
		$tmpFile = tempnam(sys_get_temp_dir(), 'test_xlsx_') . '.xlsx';
		$writer = new Xlsx($spreadsheet);
		$writer->save($tmpFile);

		$register = $this->createRegister(1);
		$schema = $this->createSchema(2, [
			'name' => ['type' => 'string'],
		]);

		try {
			$result = $this->service->importFromExcel($tmpFile, $register, $schema);
			$sheetResult = reset($result);
			$this->assertNotEmpty($sheetResult['errors']);
		} finally {
			@unlink($tmpFile);
		}
	}

	public function testImportFromExcelWithTypedProperties(): void {
		$tmpFile = $this->createTempExcel(
			['name', 'count', 'active', 'tags'],
			[['Item', '42', 'true', '["a","b"]']]
		);

		$register = $this->createRegister(1);
		$schema = $this->createSchema(2, [
			'name' => ['type' => 'string'],
			'count' => ['type' => 'integer'],
			'active' => ['type' => 'boolean'],
			'tags' => ['type' => 'array'],
		]);

		$this->objectService->method('saveObjects')
			->willReturn([
				'saved' => [['@self' => ['id' => 'uuid-1']]],
				'updated' => [],
				'unchanged' => [],
			]);

		try {
			$result = $this->service->importFromExcel($tmpFile, $register, $schema);
			$this->assertIsArray($result);
			$sheetResult = reset($result);
			$this->assertSame(1, $sheetResult['found']);
		} finally {
			@unlink($tmpFile);
		}
	}

	public function testImportFromExcelWithUnderscoreColumns(): void {
		$tmpFile = $this->createTempExcel(
			['name', '_internal'],
			[['John', 'hidden-value']]
		);

		$register = $this->createRegister(1);
		$schema = $this->createSchema(2, [
			'name' => ['type' => 'string'],
		]);

		$saved = null;
		$this->objectService->expects($this->once())
			->method('saveObjects')
			->with($this->captureObjects($saved))
			->willReturn([
				'saved' => [['@self' => ['id' => 'uuid-1']]],
				'updated' => [],
				'unchanged' => [],
			]);

		try {
			$result = $this->service->importFromExcel($tmpFile, $register, $schema);
			$this->assertIsArray($result);
			$this->assertSame([['name' => 'John', '@self' => ['register' => 1, 'schema' => 2]]], $saved);
		} finally {
			@unlink($tmpFile);
		}
	}

	public function testImportFromExcelWithAtColumnsAsAdmin(): void {
		$tmpFile = $this->createTempExcel(
			['name', '@self.organisation'],
			[['John', '12345678-1234-1234-1234-123456789abc']]
		);

		$register = $this->createRegister(1);
		$schema = $this->createSchema(2, [
			'name' => ['type' => 'string'],
		]);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$adminGroup = $this->createMock(IGroup::class);
		$adminGroup->method('inGroup')->willReturn(true);
		$this->groupManager->method('get')
			->with('admin')
			->willReturn($adminGroup);

		$saved = null;
		$this->objectService->expects($this->once())
			->method('saveObjects')
			->with($this->captureObjects($saved))
			->willReturn([
				'saved' => [['@self' => ['id' => 'uuid-1']]],
				'updated' => [],
				'unchanged' => [],
			]);

		try {
			$result = $this->service->importFromExcel(
				$tmpFile,
				$register,
				$schema,
				currentUser: $user
			);
			$this->assertIsArray($result);
			$this->assertSame([['name' => 'John', '@self' => ['organisation' => '12345678-1234-1234-1234-123456789abc', 'register' => 1, 'schema' => 2]]], $saved);
		} finally {
			@unlink($tmpFile);
		}
	}

	public function testImportFromExcelWithAtColumnsAsNonAdmin(): void {
		$tmpFile = $this->createTempExcel(
			['name', '@self.organisation'],
			[['John', 'org-uuid']]
		);

		$register = $this->createRegister(1);
		$schema = $this->createSchema(2, [
			'name' => ['type' => 'string'],
		]);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$adminGroup = $this->createMock(IGroup::class);
		$adminGroup->method('inGroup')->willReturn(false);
		$this->groupManager->method('get')
			->with('admin')
			->willReturn($adminGroup);

		$saved = null;
		$this->objectService->expects($this->once())
			->method('saveObjects')
			->with($this->captureObjects($saved))
			->willReturn([
				'saved' => [['@self' => ['id' => 'uuid-1']]],
				'updated' => [],
				'unchanged' => [],
			]);

		try {
			$result = $this->service->importFromExcel(
				$tmpFile,
				$register,
				$schema,
				currentUser: $user
			);
			$this->assertIsArray($result);
			$this->assertSame([['name' => 'John', '@self' => ['register' => 1, 'schema' => 2]]], $saved);
		} finally {
			@unlink($tmpFile);
		}
	}

	public function testImportFromExcelWithIdColumn(): void {
		$tmpFile = $this->createTempExcel(
			['id', 'name'],
			[['uuid-existing', 'John']]
		);

		$register = $this->createRegister(1);
		$schema = $this->createSchema(2, [
			'name' => ['type' => 'string'],
		]);

		$saved = null;
		$this->objectService->expects($this->once())
			->method('saveObjects')
			->with($this->captureObjects($saved))
			->willReturn([
				'saved' => [],
				'updated' => [['@self' => ['id' => 'uuid-existing']]],
				'unchanged' => [],
			]);

		try {
			$result = $this->service->importFromExcel($tmpFile, $register, $schema);
			$this->assertIsArray($result);
			$this->assertSame([['id' => 'uuid-existing', 'name' => 'John', '@self' => ['register' => 1, 'schema' => 2, 'id' => 'uuid-existing']]], $saved);
		} finally {
			@unlink($tmpFile);
		}
	}

	public function testImportFromExcelWithNoRegisterOrSchema(): void {
		$tmpFile = $this->createTempExcel(
			['name'],
			[['Test']]
		);

		try {
			// No register, no schema - should process but not save.
			$result = $this->service->importFromExcel($tmpFile);
			$this->assertIsArray($result);
			$sheetResult = reset($result);
			// Should have found 1 object but not created any (no register/schema).
			$this->assertSame(1, $sheetResult['found']);
			$this->assertEmpty($sheetResult['created']);
		} finally {
			@unlink($tmpFile);
		}
	}

	public function testImportFromExcelWithValidationErrors(): void {
		$tmpFile = $this->createTempExcel(
			['name'],
			[['John']]
		);

		$register = $this->createRegister(1);
		$schema = $this->createSchema(2, [
			'name' => ['type' => 'string'],
		]);

		$this->objectService->method('saveObjects')
			->willReturn([
				'saved' => [],
				'updated' => [],
				'unchanged' => [],
				'invalid' => [
					['object' => ['name' => 'John'], 'error' => 'Required field missing'],
				],
			]);

		try {
			$result = $this->service->importFromExcel(
				$tmpFile,
				$register,
				$schema,
				validation: true
			);
			$sheetResult = reset($result);
			$this->assertNotEmpty($sheetResult['errors']);
		} finally {
			@unlink($tmpFile);
		}
	}

	public function testImportFromExcelWithDeduplication(): void {
		$tmpFile = $this->createTempExcel(
			['name'],
			[['Alpha'], ['Beta']]
		);

		$register = $this->createRegister(1);
		$schema = $this->createSchema(2, [
			'name' => ['type' => 'string'],
		]);

		$this->objectService->method('saveObjects')
			->willReturn([
				'saved' => [['@self' => ['id' => 'uuid-1']]],
				'updated' => [],
				'unchanged' => [['@self' => ['id' => 'uuid-2']]],
			]);

		try {
			$result = $this->service->importFromExcel($tmpFile, $register, $schema);
			$sheetResult = reset($result);
			$this->assertCount(1, $sheetResult['unchanged']);
			$this->assertStringContainsString('operations avoided', $sheetResult['deduplication_efficiency']);
		} finally {
			@unlink($tmpFile);
		}
	}

	public function testImportFromExcelWithAtOtherColumnAsAdmin(): void {
		// Test @ columns that don't start with @self. in Excel import.
		$tmpFile = $this->createTempExcel(
			['name', '@other.field'],
			[['John', 'some-value']]
		);

		$register = $this->createRegister(1);
		$schema = $this->createSchema(2, [
			'name' => ['type' => 'string'],
		]);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$adminGroup = $this->createMock(IGroup::class);
		$adminGroup->method('inGroup')->willReturn(true);
		$this->groupManager->method('get')
			->with('admin')
			->willReturn($adminGroup);

		$saved = null;
		$this->objectService->expects($this->once())
			->method('saveObjects')
			->with($this->captureObjects($saved))
			->willReturn([
				'saved' => [['@self' => ['id' => 'uuid-1']]],
				'updated' => [],
				'unchanged' => [],
			]);

		try {
			$result = $this->service->importFromExcel(
				$tmpFile,
				$register,
				$schema,
				currentUser: $user
			);
			$this->assertIsArray($result);
			$this->assertSame([['name' => 'John', '@self' => ['register' => 1, 'schema' => 2]]], $saved);
		} finally {
			@unlink($tmpFile);
		}
	}

	public function testImportFromExcelWithNoSchemaSkipsTransform(): void {
		// When no schema is provided, transformObjectBySchema should not be called.
		$tmpFile = $this->createTempExcel(
			['name', 'count'],
			[['John', '42']]
		);

		$register = $this->createRegister(1);
		// Pass register but no schema to single-sheet path.
		// This should still process but data won't be type-transformed.

		$saved = null;
		$this->objectService->expects($this->once())
			->method('saveObjects')
			->with($this->captureObjects($saved))
			->willReturn([
				'saved' => [['@self' => ['id' => 'uuid-1']]],
				'updated' => [],
				'unchanged' => [],
			]);

		try {
			// Using register + schema=null triggers multi-schema path.
			// But since there's no matching schema, it errors.
			// Test the case where schema is explicitly null but register is set.
			$result = $this->service->importFromExcel($tmpFile, $register, null);
			$this->assertIsArray($result);
			$this->assertSame([['name' => 'John', 'count' => '42', '@self' => ['register' => 1, 'schema' => null]]], $saved);
		} finally {
			@unlink($tmpFile);
		}
	}

	// =========================================================================
	// Private method testing via Reflection: transformDateTimeValue
	// =========================================================================

	public function testTransformDateTimeValueMySqlFormat(): void {
		$result = $this->callCaster('dateTime', '2025-01-01 12:30:00');
		$this->assertSame('2025-01-01 12:30:00', $result);
	}

	public function testTransformDateTimeValueIso8601WithTimezone(): void {
		$result = $this->callCaster('dateTime', '2025-01-01T00:00:00+00:00');
		$this->assertSame('2025-01-01 00:00:00', $result);
	}

	public function testTransformDateTimeValueIso8601WithoutTimezone(): void {
		$result = $this->callCaster('dateTime', '2025-06-15T14:30:00');
		$this->assertSame('2025-06-15 14:30:00', $result);
	}

	public function testTransformDateTimeValueDateOnly(): void {
		$result = $this->callCaster('dateTime', '2025-01-01');
		$this->assertSame('2025-01-01 00:00:00', $result);
	}

	public function testTransformDateTimeValuePassThrough(): void {
		$result = $this->callCaster('dateTime', 'not-a-date');
		$this->assertSame('not-a-date', $result);
	}

	// =========================================================================
	// Private method testing via Reflection: transformSelfProperty
	// =========================================================================

	public function testTransformSelfPropertyPublished(): void {
		$result = $this->callCaster('castSelfProperty', 'published', '2025-01-01');
		$this->assertSame('2025-01-01 00:00:00', $result);
	}

	public function testTransformSelfPropertyCreated(): void {
		$result = $this->callCaster('castSelfProperty', 'created', '2025-01-01T12:00:00+00:00');
		$this->assertSame('2025-01-01 12:00:00', $result);
	}

	public function testTransformSelfPropertyUpdated(): void {
		// BUG-SVC-5: offset-bearing timestamps are normalised to UTC before the
		// offset is stripped. +02:00 10:30 is 08:30 UTC.
		$result = $this->callCaster('castSelfProperty', 'updated', '2025-03-15T10:30:00+02:00');
		$this->assertSame('2025-03-15 08:30:00', $result);
	}

	public function testTransformSelfPropertyOrganisationValidUuid(): void {
		$uuid = '12345678-1234-1234-1234-123456789abc';
		$result = $this->callCaster('castSelfProperty', 'organisation', $uuid);
		$this->assertSame($uuid, $result);
	}

	public function testTransformSelfPropertyOrganisationInvalidUuid(): void {
		$result = $this->callCaster('castSelfProperty', 'organisation', 'some-slug');
		$this->assertSame('some-slug', $result);
	}

	public function testTransformSelfPropertyOther(): void {
		$result = $this->callCaster('castSelfProperty', 'owner', 'admin');
		$this->assertSame('admin', $result);
	}

	// =========================================================================
	// Private method testing via Reflection: transformValueByType
	// =========================================================================

	public function testTransformValueByTypeInteger(): void {
		$result = $this->callCaster('cast', '42', ['type' => 'integer']);
		$this->assertSame(42, $result);
	}

	public function testTransformValueByTypeNumber(): void {
		$result = $this->callCaster('cast', '3.14', ['type' => 'number']);
		$this->assertSame(3.14, $result);
	}

	public function testTransformValueByTypeBoolean(): void {
		$this->assertTrue($this->callCaster('cast', 'true', ['type' => 'boolean']));
		$this->assertTrue($this->callCaster('cast', '1', ['type' => 'boolean']));
		$this->assertTrue($this->callCaster('cast', 'yes', ['type' => 'boolean']));
		$this->assertTrue($this->callCaster('cast', 'on', ['type' => 'boolean']));
		$this->assertTrue($this->callCaster('cast', 'enabled', ['type' => 'boolean']));
		$this->assertFalse($this->callCaster('cast', 'false', ['type' => 'boolean']));
		$this->assertFalse($this->callCaster('cast', '0', ['type' => 'boolean']));
		$this->assertFalse($this->callCaster('cast', 'no', ['type' => 'boolean']));
	}

	public function testTransformValueByTypeBooleanAlreadyBool(): void {
		// stringToBoolean handles actual bools.
		$this->assertTrue($this->callCaster('cast', true, ['type' => 'boolean']));
		$this->assertFalse($this->callCaster('cast', false, ['type' => 'boolean']));
	}

	public function testTransformValueByTypeArray(): void {
		// JSON array.
		$result = $this->callCaster('cast', '["a","b","c"]', ['type' => 'array']);
		$this->assertSame(['a', 'b', 'c'], $result);

		// Comma-separated.
		$result = $this->callCaster('cast', 'a,b,c', ['type' => 'array']);
		$this->assertSame(['a', 'b', 'c'], $result);

		// Single value.
		$result = $this->callCaster('cast', 'single', ['type' => 'array']);
		$this->assertSame(['single'], $result);

		// Empty string.
		$result = $this->callCaster('cast', '', ['type' => 'array']);
		$this->assertSame('', $result);  // Empty returns as-is.

		// Already array.
		$result = $this->callCaster('cast', ['x', 'y'], ['type' => 'array']);
		$this->assertSame(['x', 'y'], $result);
	}

	public function testTransformValueByTypeArrayQuotedValues(): void {
		$result = $this->callCaster('cast', '"a","b","c"', ['type' => 'array']);
		$this->assertSame(['a', 'b', 'c'], $result);

		// Single quotes.
		$result = $this->callCaster('cast', "'x','y'", ['type' => 'array']);
		$this->assertSame(['x', 'y'], $result);
	}

	public function testTransformValueByTypeArrayNonStringNonArray(): void {
		// Non-string, non-array value should be wrapped in array.
		$result = $this->callCaster('cast', 42, ['type' => 'array']);
		$this->assertSame([42], $result);
	}

	public function testTransformValueByTypeObject(): void {
		// JSON object.
		$result = $this->callCaster('cast', '{"key":"value"}', ['type' => 'object']);
		$this->assertSame(['key' => 'value'], $result);

		// Non-JSON string.
		$result = $this->callCaster('cast', 'plain-string', ['type' => 'object']);
		$this->assertSame(['value' => 'plain-string'], $result);

		// Already array.
		$result = $this->callCaster('cast', ['existing' => 'data'], ['type' => 'object']);
		$this->assertSame(['existing' => 'data'], $result);
	}

	public function testTransformValueByTypeObjectRelatedObject(): void {
		$propertyDef = [
			'type' => 'object',
			'objectConfiguration' => ['handling' => 'related-object'],
		];

		$result = $this->callCaster('cast', 'uuid-123', $propertyDef);
		$this->assertSame('uuid-123', $result);
	}

	public function testTransformValueByTypeDefaultString(): void {
		$result = $this->callCaster('cast', 42, ['type' => 'string']);
		$this->assertSame('42', $result);
	}

	public function testTransformValueByTypeNullValue(): void {
		$result = $this->callCaster('cast', null, ['type' => 'integer']);
		$this->assertNull($result);
	}

	public function testTransformValueByTypeEmptyStringValue(): void {
		$result = $this->callCaster('cast', '', ['type' => 'integer']);
		$this->assertSame('', $result);
	}

	public function testTransformValueByTypeNoTypeKey(): void {
		// Property def without explicit type should default to string.
		$result = $this->callCaster('cast', 123, []);
		$this->assertSame('123', $result);
	}

	public function testTransformValueByTypeObjectInvalidJson(): void {
		// Invalid JSON that looks like JSON (starts with { ends with }).
		$result = $this->callCaster('cast', '{not valid json}', ['type' => 'object']);
		$this->assertSame(['value' => '{not valid json}'], $result);
	}

	public function testTransformValueByTypeArrayInvalidJson(): void {
		// Invalid JSON that looks like a JSON array.
		$result = $this->callCaster('cast', '[not valid json]', ['type' => 'array']);
		// Falls through to single-value wrapping since JSON decode fails.
		$this->assertSame(['[not valid json]'], $result);
	}

	// =========================================================================
	// =========================================================================
	// Private method testing via Reflection: isUserAdmin
	// =========================================================================

	public function testIsUserAdminWithNullUser(): void {
		$ref = new ReflectionClass($this->service);
		$method = $ref->getMethod('isUserAdmin');
		$method->setAccessible(true);

		$result = $method->invoke($this->service, null);
		$this->assertFalse($result);
	}

	public function testIsUserAdminWithNoAdminGroup(): void {
		$ref = new ReflectionClass($this->service);
		$method = $ref->getMethod('isUserAdmin');
		$method->setAccessible(true);

		$this->groupManager->method('get')
			->with('admin')
			->willReturn(null);

		$user = $this->createMock(IUser::class);
		$result = $method->invoke($this->service, $user);
		$this->assertFalse($result);
	}

	public function testIsUserAdminTrue(): void {
		$ref = new ReflectionClass($this->service);
		$method = $ref->getMethod('isUserAdmin');
		$method->setAccessible(true);

		$user = $this->createMock(IUser::class);
		$adminGroup = $this->createMock(IGroup::class);
		$adminGroup->method('inGroup')->with($user)->willReturn(true);
		$this->groupManager->method('get')
			->with('admin')
			->willReturn($adminGroup);

		$result = $method->invoke($this->service, $user);
		$this->assertTrue($result);
	}

	public function testIsUserAdminFalse(): void {
		$ref = new ReflectionClass($this->service);
		$method = $ref->getMethod('isUserAdmin');
		$method->setAccessible(true);

		$user = $this->createMock(IUser::class);
		$adminGroup = $this->createMock(IGroup::class);
		$adminGroup->method('inGroup')->with($user)->willReturn(false);
		$this->groupManager->method('get')
			->with('admin')
			->willReturn($adminGroup);

		$result = $method->invoke($this->service, $user);
		$this->assertFalse($result);
	}

	// =========================================================================
	// Private method testing via Reflection: validateObjectProperties
	// =========================================================================

	// =========================================================================
	// Private method testing via Reflection: transformObjectBySchema
	// =========================================================================

	public function testTransformObjectBySchema(): void {
		$schema = $this->createSchema(1, [
			'count' => ['type' => 'integer'],
			'active' => ['type' => 'boolean'],
		]);

		$objectData = [
			'@self' => ['register' => 1, 'schema' => 1],
			'count' => '42',
			'active' => 'true',
			'unknown' => 'keep-as-is',
		];

		$result = $this->callCasterSchema($objectData, $schema);

		$this->assertSame(42, $result['count']);
		$this->assertTrue($result['active']);
		$this->assertSame('keep-as-is', $result['unknown']);
		$this->assertSame(['register' => 1, 'schema' => 1], $result['@self']);
	}

	public function testTransformObjectBySchemaWithAllTypes(): void {
		$schema = $this->createSchema(1, [
			'name' => ['type' => 'string'],
			'age' => ['type' => 'integer'],
			'score' => ['type' => 'number'],
			'active' => ['type' => 'boolean'],
			'tags' => ['type' => 'array'],
			'meta' => ['type' => 'object'],
		]);

		$objectData = [
			'@self' => ['register' => 1, 'schema' => 1],
			'name' => 123,
			'age' => '25',
			'score' => '9.5',
			'active' => 'yes',
			'tags' => 'a,b,c',
			'meta' => '{"key":"val"}',
		];

		$result = $this->callCasterSchema($objectData, $schema);

		$this->assertSame('123', $result['name']);
		$this->assertSame(25, $result['age']);
		$this->assertSame(9.5, $result['score']);
		$this->assertTrue($result['active']);
		$this->assertSame(['a', 'b', 'c'], $result['tags']);
		$this->assertSame(['key' => 'val'], $result['meta']);
	}

	// =========================================================================
	// Private method testing via Reflection: buildColumnMapping
	// =========================================================================

	public function testBuildColumnMapping(): void {
		$ref = new ReflectionClass($this->service);
		$method = $ref->getMethod('buildColumnMapping');
		$method->setAccessible(true);

		$spreadsheet = new Spreadsheet();
		$sheet = $spreadsheet->getActiveSheet();
		$sheet->setCellValue('A1', 'name');
		$sheet->setCellValue('B1', 'email');
		$sheet->setCellValue('C1', 'age');

		$result = $method->invoke($this->service, $sheet);

		$this->assertSame([
			'A' => 'name',
			'B' => 'email',
			'C' => 'age',
		], $result);
	}

	public function testBuildColumnMappingWithEmptySheet(): void {
		$ref = new ReflectionClass($this->service);
		$method = $ref->getMethod('buildColumnMapping');
		$method->setAccessible(true);

		$spreadsheet = new Spreadsheet();
		$sheet = $spreadsheet->getActiveSheet();
		// No headers.

		$result = $method->invoke($this->service, $sheet);

		$this->assertSame([], $result);
	}

	public function testBuildColumnMappingStopsAtEmptyColumn(): void {
		$ref = new ReflectionClass($this->service);
		$method = $ref->getMethod('buildColumnMapping');
		$method->setAccessible(true);

		$spreadsheet = new Spreadsheet();
		$sheet = $spreadsheet->getActiveSheet();
		$sheet->setCellValue('A1', 'name');
		// B1 is empty - should stop here.
		$sheet->setCellValue('C1', 'ignored');

		$result = $method->invoke($this->service, $sheet);

		$this->assertSame(['A' => 'name'], $result);
	}

	public function testBuildColumnMappingTrimsWhitespace(): void {
		$ref = new ReflectionClass($this->service);
		$method = $ref->getMethod('buildColumnMapping');
		$method->setAccessible(true);

		$spreadsheet = new Spreadsheet();
		$sheet = $spreadsheet->getActiveSheet();
		$sheet->setCellValue('A1', '  name  ');
		$sheet->setCellValue('B1', ' email ');

		$result = $method->invoke($this->service, $sheet);

		$this->assertSame([
			'A' => 'name',
			'B' => 'email',
		], $result);
	}

	// =========================================================================
	// Private method testing via Reflection: extractRowData
	// =========================================================================

	public function testExtractRowData(): void {
		$ref = new ReflectionClass($this->service);
		$method = $ref->getMethod('extractRowData');
		$method->setAccessible(true);

		$spreadsheet = new Spreadsheet();
		$sheet = $spreadsheet->getActiveSheet();
		$sheet->setCellValue('A1', 'name');
		$sheet->setCellValue('B1', 'email');
		$sheet->setCellValue('A2', 'John');
		$sheet->setCellValue('B2', 'john@test.nl');

		$columnMapping = ['A' => 'name', 'B' => 'email'];
		$result = $method->invoke($this->service, new MappedSheet($sheet, $columnMapping), 2);

		$this->assertSame([
			'name' => 'John',
			'email' => 'john@test.nl',
		], $result);
	}

	public function testExtractRowDataEmptyRow(): void {
		$ref = new ReflectionClass($this->service);
		$method = $ref->getMethod('extractRowData');
		$method->setAccessible(true);

		$spreadsheet = new Spreadsheet();
		$sheet = $spreadsheet->getActiveSheet();
		$sheet->setCellValue('A1', 'name');
		// Row 2 is empty.

		$columnMapping = ['A' => 'name'];
		$result = $method->invoke($this->service, new MappedSheet($sheet, $columnMapping), 2);

		$this->assertSame([], $result);
	}

	public function testExtractRowDataPartialRow(): void {
		$ref = new ReflectionClass($this->service);
		$method = $ref->getMethod('extractRowData');
		$method->setAccessible(true);

		$spreadsheet = new Spreadsheet();
		$sheet = $spreadsheet->getActiveSheet();
		$sheet->setCellValue('A2', 'John');
		// B2 is empty.

		$columnMapping = ['A' => 'name', 'B' => 'email'];
		$result = $method->invoke($this->service, new MappedSheet($sheet, $columnMapping), 2);

		$this->assertSame(['name' => 'John'], $result);
	}

	public function testExtractRowDataTrimsWhitespace(): void {
		$ref = new ReflectionClass($this->service);
		$method = $ref->getMethod('extractRowData');
		$method->setAccessible(true);

		$spreadsheet = new Spreadsheet();
		$sheet = $spreadsheet->getActiveSheet();
		$sheet->setCellValue('A2', '  John  ');

		$columnMapping = ['A' => 'name'];
		$result = $method->invoke($this->service, new MappedSheet($sheet, $columnMapping), 2);

		$this->assertSame(['name' => 'John'], $result);
	}

	// =========================================================================
	// Private method testing via Reflection: getSchemaBySlug
	// =========================================================================

	public function testGetSchemaBySlug(): void {
		$ref = new ReflectionClass($this->service);
		$method = $ref->getMethod('getSchemaBySlug');
		$method->setAccessible(true);

		$schema = $this->createSchema(1, ['name' => ['type' => 'string']]);

		$this->schemaMapper->method('find')
			->with('test-schema')
			->willReturn($schema);

		$result = $method->invoke($this->service, 'test-schema');
		$this->assertSame($schema, $result);
	}

	public function testGetSchemaBySlugThrowsOnNotFound(): void {
		$ref = new ReflectionClass($this->service);
		$method = $ref->getMethod('getSchemaBySlug');
		$method->setAccessible(true);

		$this->schemaMapper->method('find')
			->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Not found'));

		$this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);
		$method->invoke($this->service, 'nonexistent');
	}

	// =========================================================================
	// Private method testing via Reflection: transformCsvRow
	// =========================================================================

	public function testTransformCsvRowToObject(): void {
		$ref = new ReflectionClass($this->service);
		$method = $ref->getMethod('transformCsvRow');
		$method->setAccessible(true);

		$register = $this->createRegister(1);
		$schema = $this->createSchema(2, [
			'name' => ['type' => 'string'],
			'age' => ['type' => 'integer'],
		]);

		$rowData = [
			'name' => 'John',
			'age' => '30',
		];

		$result = $method->invoke($this->service, $rowData, new RowImportTarget(new RegisterSchema($register, $schema)));

		$this->assertSame('John', $result['name']);
		$this->assertSame(30, $result['age']);
		$this->assertSame(1, $result['@self']['register']);
		$this->assertSame(2, $result['@self']['schema']);
	}

	public function testTransformCsvRowToObjectWithIdField(): void {
		$ref = new ReflectionClass($this->service);
		$method = $ref->getMethod('transformCsvRow');
		$method->setAccessible(true);

		$register = $this->createRegister(1);
		$schema = $this->createSchema(2, [
			'name' => ['type' => 'string'],
		]);

		$rowData = [
			'id' => 'existing-uuid',
			'name' => 'John',
		];

		$result = $method->invoke($this->service, $rowData, new RowImportTarget(new RegisterSchema($register, $schema)));

		$this->assertSame('existing-uuid', $result['@self']['id']);
	}

	public function testTransformCsvRowToObjectSkipsEmptyValues(): void {
		$ref = new ReflectionClass($this->service);
		$method = $ref->getMethod('transformCsvRow');
		$method->setAccessible(true);

		$register = $this->createRegister(1);
		$schema = $this->createSchema(2, [
			'name' => ['type' => 'string'],
			'email' => ['type' => 'string'],
		]);

		$rowData = [
			'name' => 'John',
			'email' => '',
			'phone' => null,
		];

		$result = $method->invoke($this->service, $rowData, new RowImportTarget(new RegisterSchema($register, $schema)));

		$this->assertSame('John', $result['name']);
		$this->assertArrayNotHasKey('email', $result);
		$this->assertArrayNotHasKey('phone', $result);
	}

	public function testTransformCsvRowToObjectCachesSchemaProperties(): void {
		$ref = new ReflectionClass($this->service);
		$method = $ref->getMethod('transformCsvRow');
		$method->setAccessible(true);

		$register = $this->createRegister(1);
		$schema = $this->createSchema(2, [
			'name' => ['type' => 'string'],
		]);

		$rowData = ['name' => 'John'];

		// Call twice to test caching.
		$method->invoke($this->service, $rowData, new RowImportTarget(new RegisterSchema($register, $schema)));
		$result = $method->invoke($this->service, $rowData, new RowImportTarget(new RegisterSchema($register, $schema)));

		$this->assertSame('John', $result['name']);
	}

	// =========================================================================
	// Private method testing via Reflection: transformExcelRowToObject
	// =========================================================================

	public function testTransformExcelRowToObject(): void {
		$ref = new ReflectionClass($this->service);
		$method = $ref->getMethod('transformExcelRowToObject');
		$method->setAccessible(true);

		$register = $this->createRegister(1);
		$schema = $this->createSchema(2, [
			'name' => ['type' => 'string'],
			'age' => ['type' => 'integer'],
		]);

		$rowData = [
			'name' => 'John',
			'age' => '30',
		];

		$result = $method->invoke($this->service, $rowData, new ImportTarget($register, $schema, null));

		$this->assertSame('John', $result['name']);
		$this->assertSame(30, $result['age']);
		$this->assertSame(1, $result['@self']['register']);
		$this->assertSame(2, $result['@self']['schema']);
	}

	public function testTransformExcelRowToObjectWithoutSchema(): void {
		$ref = new ReflectionClass($this->service);
		$method = $ref->getMethod('transformExcelRowToObject');
		$method->setAccessible(true);

		$register = $this->createRegister(1);

		$rowData = [
			'name' => 'John',
			'age' => '30',
		];

		$result = $method->invoke($this->service, $rowData, new ImportTarget($register, null, null));

		// Without schema, values should NOT be transformed.
		$this->assertSame('John', $result['name']);
		$this->assertSame('30', $result['age']);
		$this->assertSame(1, $result['@self']['register']);
	}

	public function testTransformExcelRowToObjectWithoutRegister(): void {
		$ref = new ReflectionClass($this->service);
		$method = $ref->getMethod('transformExcelRowToObject');
		$method->setAccessible(true);

		$schema = $this->createSchema(2, [
			'name' => ['type' => 'string'],
		]);

		$rowData = ['name' => 'John'];

		$result = $method->invoke($this->service, $rowData, new ImportTarget(null, $schema, null));

		$this->assertSame('John', $result['name']);
		$this->assertArrayNotHasKey('register', $result['@self']);
		$this->assertSame(2, $result['@self']['schema']);
	}

	public function testTransformExcelRowToObjectWithIdField(): void {
		$ref = new ReflectionClass($this->service);
		$method = $ref->getMethod('transformExcelRowToObject');
		$method->setAccessible(true);

		$register = $this->createRegister(1);
		$schema = $this->createSchema(2, [
			'name' => ['type' => 'string'],
		]);

		$rowData = [
			'id' => 'existing-uuid',
			'name' => 'John',
		];

		$result = $method->invoke($this->service, $rowData, new ImportTarget($register, $schema, null));

		$this->assertSame('existing-uuid', $result['@self']['id']);
	}

	public function testTransformExcelRowToObjectSkipsEmptyValues(): void {
		$ref = new ReflectionClass($this->service);
		$method = $ref->getMethod('transformExcelRowToObject');
		$method->setAccessible(true);

		$register = $this->createRegister(1);
		$schema = $this->createSchema(2, [
			'name' => ['type' => 'string'],
		]);

		$rowData = [
			'name' => 'John',
			'email' => '',
			'phone' => null,
		];

		$result = $method->invoke($this->service, $rowData, new ImportTarget($register, $schema, null));

		$this->assertSame('John', $result['name']);
		$this->assertArrayNotHasKey('email', $result);
		$this->assertArrayNotHasKey('phone', $result);
	}

	public function testTransformExcelRowToObjectWithUnderscoreColumns(): void {
		$ref = new ReflectionClass($this->service);
		$method = $ref->getMethod('transformExcelRowToObject');
		$method->setAccessible(true);

		$register = $this->createRegister(1);
		$schema = $this->createSchema(2, [
			'name' => ['type' => 'string'],
		]);

		$rowData = [
			'name' => 'John',
			'_internal' => 'hidden',
		];

		$result = $method->invoke($this->service, $rowData, new ImportTarget($register, $schema, null));

		$this->assertSame('John', $result['name']);
		$this->assertArrayNotHasKey('_internal', $result);
	}

	public function testTransformExcelRowToObjectWithAtColumnsAsAdmin(): void {
		$ref = new ReflectionClass($this->service);
		$method = $ref->getMethod('transformExcelRowToObject');
		$method->setAccessible(true);

		$register = $this->createRegister(1);
		$schema = $this->createSchema(2, [
			'name' => ['type' => 'string'],
		]);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$adminGroup = $this->createMock(IGroup::class);
		$adminGroup->method('inGroup')->willReturn(true);
		$this->groupManager->method('get')
			->with('admin')
			->willReturn($adminGroup);

		$rowData = [
			'name' => 'John',
			'@self.organisation' => '12345678-1234-1234-1234-123456789abc',
		];

		$result = $method->invoke($this->service, $rowData, new ImportTarget($register, $schema, $user));

		$this->assertSame('12345678-1234-1234-1234-123456789abc', $result['@self']['organisation']);
	}

	public function testTransformExcelRowToObjectWithAtColumnsAsNonAdmin(): void {
		$ref = new ReflectionClass($this->service);
		$method = $ref->getMethod('transformExcelRowToObject');
		$method->setAccessible(true);

		$register = $this->createRegister(1);
		$schema = $this->createSchema(2, [
			'name' => ['type' => 'string'],
		]);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$adminGroup = $this->createMock(IGroup::class);
		$adminGroup->method('inGroup')->willReturn(false);
		$this->groupManager->method('get')
			->with('admin')
			->willReturn($adminGroup);

		$rowData = [
			'name' => 'John',
			'@self.organisation' => 'org-uuid',
		];

		$result = $method->invoke($this->service, $rowData, new ImportTarget($register, $schema, $user));

		// @ columns should be skipped for non-admin.
		$this->assertArrayNotHasKey('organisation', $result['@self']);
	}

	public function testTransformExcelRowToObjectWithAtOtherColumn(): void {
		$ref = new ReflectionClass($this->service);
		$method = $ref->getMethod('transformExcelRowToObject');
		$method->setAccessible(true);

		$register = $this->createRegister(1);
		$schema = $this->createSchema(2, [
			'name' => ['type' => 'string'],
		]);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$adminGroup = $this->createMock(IGroup::class);
		$adminGroup->method('inGroup')->willReturn(true);
		$this->groupManager->method('get')
			->with('admin')
			->willReturn($adminGroup);

		$rowData = [
			'name' => 'John',
			'@other.field' => 'value',
		];

		$result = $method->invoke($this->service, $rowData, new ImportTarget($register, $schema, $user));

		// @other columns should be ignored (not in @self, not in objectData).
		$this->assertArrayNotHasKey('@other.field', $result);
	}

	// =========================================================================
	// Private method testing via Reflection: stringToArray
	// =========================================================================

	public function testStringToArrayWithEmptyString(): void {
		$result = $this->callCaster('arrayValue', '');
		$this->assertSame([], $result);
	}

	public function testStringToArrayWithWhitespaceOnly(): void {
		$result = $this->callCaster('arrayValue', '   ');
		$this->assertSame([], $result);
	}

	public function testStringToArrayWithJsonArray(): void {
		$result = $this->callCaster('arrayValue', '[1, 2, 3]');
		$this->assertSame([1, 2, 3], $result);
	}

	public function testStringToArrayWithCommaSeparated(): void {
		$result = $this->callCaster('arrayValue', 'a, b, c');
		$this->assertSame(['a', 'b', 'c'], $result);
	}

	public function testStringToArrayWithSingleValue(): void {
		$result = $this->callCaster('arrayValue', 'single');
		$this->assertSame(['single'], $result);
	}

	public function testStringToArrayWithExistingArray(): void {
		$result = $this->callCaster('arrayValue', ['a', 'b']);
		$this->assertSame(['a', 'b'], $result);
	}

	public function testStringToArrayWithNonStringNonArray(): void {
		$result = $this->callCaster('arrayValue', 42);
		$this->assertSame([42], $result);
	}

	// =========================================================================
	// Private method testing via Reflection: stringToObject
	// =========================================================================

	public function testStringToObjectWithValidJson(): void {
		$result = $this->callCasterObject('{"name":"John","age":30}');
		$this->assertSame(['name' => 'John', 'age' => 30], $result);
	}

	public function testStringToObjectWithInvalidJson(): void {
		$result = $this->callCasterObject('{invalid}');
		$this->assertSame(['value' => '{invalid}'], $result);
	}

	public function testStringToObjectWithPlainString(): void {
		$result = $this->callCasterObject('hello world');
		$this->assertSame(['value' => 'hello world'], $result);
	}

	public function testStringToObjectWithExistingArray(): void {
		$result = $this->callCasterObject(['key' => 'val']);
		$this->assertSame(['key' => 'val'], $result);
	}

	public function testStringToObjectWithStdClass(): void {
		$obj = new \stdClass();
		$obj->name = 'test';
		$result = $this->callCasterObject($obj);
		$this->assertSame('test', $result->name);
	}

	// =========================================================================
	// Private method testing via Reflection: stringToBoolean
	// =========================================================================

	public function testStringToBooleanWithVariousInputs(): void {
		// True values.
		$this->assertTrue($this->callCaster('booleanValue', 'TRUE'));
		$this->assertTrue($this->callCaster('booleanValue', 'True'));
		$this->assertTrue($this->callCaster('booleanValue', ' true '));
		$this->assertTrue($this->callCaster('booleanValue', 'YES'));
		$this->assertTrue($this->callCaster('booleanValue', 'ON'));
		$this->assertTrue($this->callCaster('booleanValue', 'ENABLED'));

		// False values.
		$this->assertFalse($this->callCaster('booleanValue', 'false'));
		$this->assertFalse($this->callCaster('booleanValue', 'off'));
		$this->assertFalse($this->callCaster('booleanValue', 'disabled'));
		$this->assertFalse($this->callCaster('booleanValue', 'random'));

		// Bool values.
		$this->assertTrue($this->callCaster('booleanValue', true));
		$this->assertFalse($this->callCaster('booleanValue', false));
	}

	// =========================================================================
	// Integration-style: CSV import with all options
	// =========================================================================

	public function testImportFromCsvWithEventsAndEnrichDisabled(): void {
		$tmpFile = tempnam(sys_get_temp_dir(), 'test_import_');
		file_put_contents($tmpFile, "name\nJohn\n");

		$register = $this->createRegister(1);
		$schema = $this->createSchema(2, [
			'name' => ['type' => 'string'],
		]);

		$saved = null;
		$this->objectService->expects($this->once())
			->method('saveObjects')
			->with($this->captureObjects($saved))
			->willReturn([
				'saved' => [['@self' => ['id' => 'uuid-1']]],
				'updated' => [],
				'unchanged' => [],
			]);

		try {
			$result = $this->service->importFromCsv(
				$tmpFile,
				$register,
				$schema,
				validation: false,
				events: true,
				_rbac: false,
				_multitenancy: false,
				publish: false,
				enrich: false
			);
			$this->assertIsArray($result);
			$this->assertSame([['name' => 'John', '@self' => ['register' => 1, 'schema' => 2]]], $saved);
		} finally {
			@unlink($tmpFile);
		}
	}

	public function testImportFromCsvPerformanceMetricsComplete(): void {
		$tmpFile = tempnam(sys_get_temp_dir(), 'test_import_');
		file_put_contents($tmpFile, "name\nAlpha\nBeta\nGamma\n");

		$register = $this->createRegister(1);
		$schema = $this->createSchema(2, [
			'name' => ['type' => 'string'],
		]);

		$this->objectService->method('saveObjects')
			->willReturn([
				'saved' => [
					['@self' => ['id' => 'uuid-1']],
					['@self' => ['id' => 'uuid-2']],
					['@self' => ['id' => 'uuid-3']],
				],
				'updated' => [],
				'unchanged' => [],
			]);

		try {
			$result = $this->service->importFromCsv($tmpFile, $register, $schema);
			$sheetResult = reset($result);

			$perf = $sheetResult['performance'];
			$this->assertArrayHasKey('totalTime', $perf);
			$this->assertArrayHasKey('totalTimeMs', $perf);
			$this->assertArrayHasKey('objectsPerSecond', $perf);
			$this->assertArrayHasKey('totalProcessed', $perf);
			$this->assertArrayHasKey('totalFound', $perf);
			$this->assertArrayHasKey('efficiency', $perf);
			$this->assertSame(3, $perf['totalProcessed']);
			$this->assertSame(3, $perf['totalFound']);
		} finally {
			@unlink($tmpFile);
		}
	}

	public function testImportFromExcelWithSelfCreatedColumnAsAdmin(): void {
		$tmpFile = $this->createTempExcel(
			['name', '@self.created'],
			[['John', '2025-01-01T00:00:00+00:00']]
		);

		$register = $this->createRegister(1);
		$schema = $this->createSchema(2, [
			'name' => ['type' => 'string'],
		]);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$adminGroup = $this->createMock(IGroup::class);
		$adminGroup->method('inGroup')->willReturn(true);
		$this->groupManager->method('get')
			->with('admin')
			->willReturn($adminGroup);

		$saved = null;
		$this->objectService->expects($this->once())
			->method('saveObjects')
			->with($this->captureObjects($saved))
			->willReturn([
				'saved' => [['@self' => ['id' => 'uuid-1']]],
				'updated' => [],
				'unchanged' => [],
			]);

		try {
			$result = $this->service->importFromExcel(
				$tmpFile,
				$register,
				$schema,
				currentUser: $user
			);
			$this->assertIsArray($result);
			$this->assertSame([['name' => 'John', '@self' => ['created' => '2025-01-01 00:00:00', 'register' => 1, 'schema' => 2]]], $saved);
		} finally {
			@unlink($tmpFile);
		}
	}

	/**
	 * Call a method of the value caster, private ones included.
	 *
	 * @param string $name    The method
	 * @param mixed  ...$args The arguments
	 *
	 * @return mixed The result
	 */
	private function callCaster(string $name, mixed ...$args): mixed {
		return (new \ReflectionMethod(ImportValueCaster::class, $name))->invoke(new ImportValueCaster(), ...$args);
	}

	/**
	 * Cast a value as a plain object property.
	 *
	 * @param mixed $value The value
	 *
	 * @return mixed The result
	 */
	private function callCasterObject(mixed $value): mixed {
		return $this->callCaster('objectValue', $value, []);
	}

	/**
	 * Cast an object by a schema's properties.
	 *
	 * @param array<string, mixed> $object The object
	 * @param Schema               $schema The schema
	 *
	 * @return array<string, mixed> The result
	 */
	private function callCasterSchema(array $object, Schema $schema): array {
		return (new ImportValueCaster())->castObject(objectData: $object, properties: $schema->getProperties());
	}
}
