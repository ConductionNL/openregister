<?php

declare(strict_types=1);

/**
 * ImportService register-bundle auto-create tests
 *
 * Proves the register-import-auto-create gap (#1487) is closed:
 * `ImportService::importFromJson()` auto-creates a missing register (and its
 * schemas) from a register-bundle envelope, reusing
 * `ConfigurationImportHandler::importRegister()` /
 * `::importSchema()` (ADR-011 — no duplicated creation logic), stays
 * idempotent on re-import, and fails with a clear, slug-naming error for a
 * plain object list targeting a register that does not exist and cannot be
 * auto-created.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 *
 * @spec openspec/changes/register-import-auto-create/specs/data-import-export/spec.md
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use GuzzleHttp\Client;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\RegisterNotFoundException;
use OCA\OpenRegister\Service\Configuration\ImportHandler;
use OCA\OpenRegister\Service\Configuration\UploadHandler;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\MigrationPack\MappingEngine;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Translation\TranslationCsvCodec;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;

class ImportServiceRegisterAutoCreateTest extends TestCase {

	/** @var RegisterMapper&MockObject */
	private RegisterMapper $registerMapper;

	/** @var SchemaMapper&MockObject */
	private SchemaMapper $schemaMapper;

	/** @var ObjectService&MockObject */
	private ObjectService $objectService;

	private ImportHandler $importHandler;

	private ImportService $service;

	/**
	 * @var list<string>
	 */
	private array $createdFiles = [];

	protected function setUp(): void {
		parent::setUp();

		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->objectService = $this->createMock(ObjectService::class);

		$this->schemaMapper->method('getSlugToIdMap')->willReturn([]);

		// Real ImportHandler (mirrors ImportHandlerTest.php's pattern) so
		// importRegister()/importSchema()'s find-or-create logic runs for
		// real; only the mappers underneath are mocked.
		$this->importHandler = new ImportHandler(
			schemaMapper: $this->schemaMapper,
			registerMapper: $this->registerMapper,
			objectEntityMapper: $this->createMock(MagicMapper::class),
			configurationMapper: $this->createMock(ConfigurationMapper::class),
			mappingMapper: $this->createMock(MappingMapper::class),
			client: $this->createMock(Client::class),
			appConfig: $this->createMock(IAppConfig::class),
			logger: $this->createMock(LoggerInterface::class),
			appDataPath: sys_get_temp_dir(),
			uploadHandler: $this->createMock(UploadHandler::class),
			objectService: $this->objectService
		);

		$importHandler = $this->importHandler;
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')
			->willReturnCallback(
				static function (string $id) use ($importHandler) {
					if ($id === ImportHandler::class) {
						return $importHandler;
					}

					throw new \RuntimeException('Unexpected container->get() call: ' . $id);
				}
			);

		$translationCsvCodec = $this->createMock(TranslationCsvCodec::class);
		$translationCsvCodec->method('unflattenFromCsv')->willReturnCallback(static fn (array $row) => $row);

		$this->service = new ImportService(
			$this->schemaMapper,
			$this->objectService,
			$this->createMock(LoggerInterface::class),
			$this->createMock(IGroupManager::class),
			$translationCsvCodec,
			$this->createMock(\OCA\OpenRegister\Db\AuditTrailMapper::class),
			new MappingEngine(),
			$this->createMock(ValidateObject::class),
			$container
		);
	}//end setUp()

	protected function tearDown(): void {
		foreach ($this->createdFiles as $file) {
			if (is_file($file) === true) {
				unlink($file);
			}
		}

		parent::tearDown();
	}//end tearDown()

	private function createRegisterEntity(int $id, string $slug, string $version): Register {
		$register = new Register();
		$register->setSlug($slug);
		$register->setTitle(ucfirst($slug));
		$register->setVersion($version);
		$ref = new ReflectionClass($register);
		$prop = $ref->getProperty('id');
		$prop->setAccessible(true);
		$prop->setValue($register, $id);
		return $register;
	}//end createRegisterEntity()

	private function createSchemaEntity(int $id, string $slug, array $properties, string $version): Schema {
		$schema = new Schema();
		$schema->setSlug($slug);
		$schema->setTitle(ucfirst($slug));
		$schema->setProperties($properties);
		$schema->setVersion($version);
		$ref = new ReflectionClass($schema);
		$prop = $ref->getProperty('id');
		$prop->setAccessible(true);
		$prop->setValue($schema, $id);
		return $schema;
	}//end createSchemaEntity()

	private function writeTempJsonFile(array $data): string {
		$path = tempnam(sys_get_temp_dir(), 'register_bundle_test_') . '.json';
		file_put_contents($path, json_encode($data));
		$this->createdFiles[] = $path;
		return $path;
	}//end writeTempJsonFile()

	/**
	 * A minimal register-bundle envelope: one register ("products"), one
	 * schema ("product"), one object — the same components.registers /
	 * components.schemas / components.objects shape
	 * ExportHandler::exportConfig() produces and
	 * ConfigurationService::importFromApp() consumes.
	 */
	private function productsBundle(string $version = '1.0.0'): array {
		return [
			'openapi' => '3.0.0',
			'info' => [
				'title' => 'Products',
				'version' => $version,
			],
			'components' => [
				'registers' => [
					'products' => [
						'slug' => 'products',
						'title' => 'Products',
						'description' => 'Product catalogue',
						'version' => $version,
						'schemas' => ['product'],
					],
				],
				'schemas' => [
					'product' => [
						'slug' => 'product',
						'title' => 'Product',
						'version' => $version,
						'properties' => [
							'name' => ['type' => 'string'],
						],
					],
				],
				'objects' => [
					[
						'@self' => [
							'register' => 'products',
							'schema' => 'product',
						],
						'name' => 'Widget',
					],
				],
			],
		];
	}//end productsBundle()

	/**
	 * REQ-IMP-AC-01: a register bundle for a non-existent register
	 * auto-creates the register + schema and imports the bundle's objects.
	 */
	public function testBundleAutoCreatesRegisterAndSchemaAndImportsObjects(): void {
		$this->registerMapper->method('find')
			->willThrowException(new DoesNotExistException('register not found'));

		$createdRegister = $this->createRegisterEntity(id: 1, slug: 'products', version: '1.0.0');
		$this->registerMapper->expects($this->once())
			->method('createFromArray')
			->willReturn($createdRegister);

		$this->schemaMapper->method('find')
			->willThrowException(new DoesNotExistException('schema not found'));

		$createdSchema = $this->createSchemaEntity(
			id: 1,
			slug: 'product',
			properties: ['name' => ['type' => 'string']],
			version: '1.0.0'
		);
		$this->schemaMapper->expects($this->once())
			->method('createFromArray')
			->willReturn($createdSchema);
		$this->schemaMapper->method('update')->willReturnArgument(0);

		// getUuid() is an NC Entity magic accessor — mocks can't configure it;
		// use a real entity with the uuid set instead.
		$savedObject = new ObjectEntity();
		$savedObject->setUuid('uuid-widget-1');
		$this->objectService->expects($this->once())
			->method('saveObject')
			->willReturn($savedObject);

		$path = $this->writeTempJsonFile($this->productsBundle());

		$result = $this->service->importFromJson(
			filePath: $path,
			register: null,
			schema: null,
			registerSlug: 'products'
		);

		$this->assertSame(['uuid-widget-1'], $result['JSON']['created']);
		$this->assertSame([], $result['JSON']['errors']);
		$this->assertSame('product', $result['JSON']['schema']['slug']);
	}//end testBundleAutoCreatesRegisterAndSchemaAndImportsObjects()

	/**
	 * REQ-IMP-AC-03: re-importing a bundle whose register already exists
	 * must not create a duplicate register (or schema) — only the very
	 * first import's createFromArray() calls happen.
	 */
	public function testReimportOfExistingBundleDoesNotDuplicateRegister(): void {
		$registerExists = false;
		$existingRegister = null;
		$this->registerMapper->method('find')
			->willReturnCallback(
				function () use (&$registerExists, &$existingRegister) {
					if ($registerExists === true) {
						return $existingRegister;
					}

					throw new DoesNotExistException('register not found');
				}
			);
		$this->registerMapper->expects($this->once())
			->method('createFromArray')
			->willReturnCallback(
				function (array $data) use (&$registerExists, &$existingRegister) {
					$existingRegister = $this->createRegisterEntity(
						id: 1,
						slug: $data['slug'],
						version: $data['version']
					);
					$registerExists = true;
					return $existingRegister;
				}
			);

		$schemaExists = false;
		$existingSchema = null;
		$this->schemaMapper->method('find')
			->willReturnCallback(
				function () use (&$schemaExists, &$existingSchema) {
					if ($schemaExists === true) {
						return $existingSchema;
					}

					throw new DoesNotExistException('schema not found');
				}
			);
		$this->schemaMapper->expects($this->once())
			->method('createFromArray')
			->willReturnCallback(
				function (array $data) use (&$schemaExists, &$existingSchema) {
					$existingSchema = $this->createSchemaEntity(
						id: 1,
						slug: $data['slug'],
						properties: ($data['properties'] ?? []),
						version: $data['version']
					);
					$schemaExists = true;
					return $existingSchema;
				}
			);
		$this->schemaMapper->method('update')->willReturnArgument(0);

		// getUuid() is an NC Entity magic accessor — mocks can't configure it;
		// use a real entity with the uuid set instead.
		$savedObject = new ObjectEntity();
		$savedObject->setUuid('uuid-widget-1');
		$this->objectService->method('saveObject')->willReturn($savedObject);

		$path = $this->writeTempJsonFile($this->productsBundle());

		// First import: creates the register + schema.
		$this->service->importFromJson(filePath: $path, register: null, schema: null, registerSlug: 'products');

		// Second import of the SAME bundle: registerMapper::createFromArray
		// and schemaMapper::createFromArray each remain asserted ->once()
		// above — a second invocation would fail this test.
		$result = $this->service->importFromJson(filePath: $path, register: null, schema: null, registerSlug: 'products');

		$this->assertSame([], $result['JSON']['errors']);
	}//end testReimportOfExistingBundleDoesNotDuplicateRegister()

	/**
	 * REQ-IMP-AC-02: a plain object list (no register-bundle metadata)
	 * targeting a register that does not exist fails with a clear error
	 * naming the missing slug — not a silent no-op, not an opaque exception.
	 */
	public function testPlainObjectListForMissingRegisterThrowsClearNamedError(): void {
		$path = $this->writeTempJsonFile(
			[
				['name' => 'Order 1'],
				['name' => 'Order 2'],
			]
		);

		try {
			$this->service->importFromJson(
				filePath: $path,
				register: null,
				schema: null,
				registerSlug: 'orders'
			);
			$this->fail('Expected RegisterNotFoundException to be thrown.');
		} catch (RegisterNotFoundException $e) {
			$this->assertStringContainsString('orders', $e->getMessage());
			// Names both remedies: create first, or supply a full bundle.
			$this->assertStringContainsString('bundle', $e->getMessage());
			$this->assertStringContainsString('Create the register first', $e->getMessage());
			// A 4xx code — the controller maps any Exception here to an
			// actionable 400 regardless, but the exception's own code must
			// never be a 5xx.
			$this->assertGreaterThanOrEqual(400, $e->getCode());
			$this->assertLessThan(500, $e->getCode());
		}

		// Register/schema creation must never be attempted for a non-bundle payload.
		$this->registerMapper->expects($this->never())->method('createFromArray');
		$this->schemaMapper->expects($this->never())->method('createFromArray');
	}//end testPlainObjectListForMissingRegisterThrowsClearNamedError()
}//end class
