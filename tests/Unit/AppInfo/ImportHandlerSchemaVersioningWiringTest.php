<?php

declare(strict_types=1);

/**
 * The container hands the import handler its schema versioning service (#4102).
 *
 * A setter with a green test suite and no caller is a guard that never runs,
 * so this asserts the wiring from the caller: the Application's optional
 * import services.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\AppInfo
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 * @link     https://www.OpenRegister.nl
 *
 * @spec openspec/specs/schema-migration/spec.md
 */

namespace OCA\OpenRegister\Tests\Unit\AppInfo;

use GuzzleHttp\Client;
use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Configuration\ImportHandler;
use OCA\OpenRegister\Service\Configuration\UploadHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Schema\SchemaVersioningService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

/**
 * Application wiring of the import handler's schema versioning.
 */
class ImportHandlerSchemaVersioningWiringTest extends TestCase {

	/**
	 * The optional import services include the schema versioning service.
	 *
	 * @return void
	 */
	public function testTheImportHandlerIsGivenTheSchemaVersioningService(): void {
		$handler = new ImportHandler(
			schemaMapper: $this->createMock(SchemaMapper::class),
			registerMapper: $this->createMock(RegisterMapper::class),
			objectEntityMapper: $this->createMock(MagicMapper::class),
			configurationMapper: $this->createMock(ConfigurationMapper::class),
			mappingMapper: $this->createMock(MappingMapper::class),
			client: $this->createMock(Client::class),
			appConfig: $this->createMock(IAppConfig::class),
			logger: $this->createMock(LoggerInterface::class),
			appDataPath: '/tmp',
			uploadHandler: $this->createMock(UploadHandler::class),
			objectService: $this->createMock(ObjectService::class)
		);

		$versioning = $this->createMock(SchemaVersioningService::class);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($versioning): object {
				if ($id === SchemaVersioningService::class) {
					return $versioning;
				}

				throw new RuntimeException('not in this test: ' . $id);
			}
		);

		// Application::__construct() boots the app container, which a unit test has not got.
		$app = (new ReflectionClass(Application::class))->newInstanceWithoutConstructor();
		(new ReflectionMethod(Application::class, 'attachOptionalImportServices'))->invoke(
			$app,
			$handler,
			$container,
			$this->createMock(LoggerInterface::class)
		);

		$this->assertSame($versioning, (new ReflectionProperty(ImportHandler::class, 'schemaVersioning'))->getValue($handler));
	}//end testTheImportHandlerIsGivenTheSchemaVersioningService()

	/**
	 * The factory hands the import its selectielijst seeder, or `components.selectionLists` is dropped.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function testTheImportHandlerIsGivenTheSelectionListSeeder(): void {
		$handler = new ImportHandler(
			schemaMapper: $this->createMock(SchemaMapper::class),
			registerMapper: $this->createMock(RegisterMapper::class),
			objectEntityMapper: $this->createMock(MagicMapper::class),
			configurationMapper: $this->createMock(ConfigurationMapper::class),
			mappingMapper: $this->createMock(MappingMapper::class),
			client: $this->createMock(Client::class),
			appConfig: $this->createMock(IAppConfig::class),
			logger: $this->createMock(LoggerInterface::class),
			appDataPath: '/tmp',
			uploadHandler: $this->createMock(UploadHandler::class),
			objectService: $this->createMock(ObjectService::class)
		);

		$seeder = $this->createMock(\OCA\OpenRegister\Service\Archival\SelectionListSeeder::class);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($seeder): object {
				if ($id === \OCA\OpenRegister\Service\Archival\SelectionListSeeder::class) {
					return $seeder;
				}

				throw new RuntimeException('not in this test: ' . $id);
			}
		);

		$app = (new ReflectionClass(Application::class))->newInstanceWithoutConstructor();
		(new ReflectionMethod(Application::class, 'attachOptionalImportServices'))->invoke(
			$app,
			$handler,
			$container,
			$this->createMock(LoggerInterface::class)
		);

		$this->assertSame($seeder, (new ReflectionProperty(ImportHandler::class, 'selectionListSeeder'))->getValue($handler));
	}//end testTheImportHandlerIsGivenTheSelectionListSeeder()
}//end class
