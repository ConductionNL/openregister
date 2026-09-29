<?php

/**
 * Tests that importFromApp() gives the registers it imports their Files folder.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/register-folder-at-import/specs/file-actions/spec.md#requirement-an-app-imported-register-has-its-files-folder-when-the-import-returns-req-rfai-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use GuzzleHttp\Client;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Configuration\ImportHandler;
use OCA\OpenRegister\Service\Configuration\UploadHandler;
use OCA\OpenRegister\Service\File\RegisterFolderProvisioner;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Configuration\ImportHandler
 * @uses \OCA\OpenRegister\Db\Configuration
 * @uses \OCA\OpenRegister\Db\Register
 */
class ImportHandlerRegisterFolderTest extends TestCase {

	/**
	 * Register mapper double.
	 *
	 * @var RegisterMapper&MockObject
	 */
	private RegisterMapper&MockObject $registerMapper;

	/**
	 * The register the import creates.
	 *
	 * @var Register
	 */
	private Register $created;

	/**
	 * The handler under test.
	 *
	 * @var ImportHandler
	 */
	private ImportHandler $handler;

	/**
	 * Wire an import that creates one register.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$config = new Configuration();
		$config->setApp('portaliq');
		$config->setVersion('0.1.0');
		$config->setRegisters([]);
		$config->setSchemas([]);
		$config->setObjects([]);
		$ref = new ReflectionClass($config);
		$prop = $ref->getProperty('id');
		$prop->setAccessible(true);
		$prop->setValue($config, 7);

		$configurationMapper = $this->createMock(ConfigurationMapper::class);
		$configurationMapper->method('findBySourceUrl')->willReturn(null);
		$configurationMapper->method('findByApp')->willReturn([$config]);
		$configurationMapper->method('update')->willReturnArgument(0);

		$this->created = new Register();
		$this->created->setId(5);
		$this->created->setSlug('portal');

		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->registerMapper->method('find')->willThrowException(new DoesNotExistException('none'));
		$this->registerMapper->method('createFromArray')->willReturn($this->created);
		$this->registerMapper->method('update')->willReturnArgument(0);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');

		$this->handler = new ImportHandler(
			schemaMapper: $this->createMock(SchemaMapper::class),
			registerMapper: $this->registerMapper,
			objectEntityMapper: $this->createMock(MagicMapper::class),
			configurationMapper: $configurationMapper,
			mappingMapper: $this->createMock(MappingMapper::class),
			client: $this->createMock(Client::class),
			appConfig: $appConfig,
			logger: $this->createMock(LoggerInterface::class),
			appDataPath: '/tmp',
			uploadHandler: $this->createMock(UploadHandler::class),
			objectService: $this->createMock(ObjectService::class)
		);
	}

	/**
	 * App configuration data that ships one register.
	 *
	 * @return array<string, mixed>
	 */
	private function data(): array {
		return [
			'components' => [
				'registers' => [
					'portal' => ['slug' => 'portal', 'title' => 'Portal', 'version' => '1.0.0'],
				],
			],
		];
	}

	/**
	 * The provisioner receives exactly the registers the import returned.
	 *
	 * @return void
	 */
	public function testImportedRegistersAreProvisioned(): void {
		$provisioner = $this->createMock(RegisterFolderProvisioner::class);
		$provisioner->expects($this->once())
			->method('ensureFolders')
			->with(
				$this->callback(
					fn (array $registers): bool => count($registers) === 1 && $registers[0] === $this->created
				)
			)
			->willReturn(['provisioned' => 1, 'present' => 0, 'failed' => 0]);
		$this->handler->setRegisterFolderProvisioner($provisioner);

		$result = $this->handler->importFromApp('portaliq', $this->data(), '0.2.0');

		$this->assertSame([$this->created], array_values($result['registers']));
	}

	/**
	 * A provisioner that throws does not fail the import.
	 *
	 * @return void
	 */
	public function testAThrowingProvisionerDoesNotFailTheImport(): void {
		$provisioner = $this->createMock(RegisterFolderProvisioner::class);
		$provisioner->method('ensureFolders')->willThrowException(new RuntimeException('storage gone'));
		$this->handler->setRegisterFolderProvisioner($provisioner);

		$result = $this->handler->importFromApp('portaliq', $this->data(), '0.2.0');

		$this->assertCount(1, $result['registers']);
	}

	/**
	 * Without a provisioner the import runs as before and the register has no folder.
	 *
	 * @return void
	 */
	public function testWithoutAProvisionerTheImportRunsAsBefore(): void {
		$result = $this->handler->importFromApp('portaliq', $this->data(), '0.2.0');

		$this->assertCount(1, $result['registers']);
		$this->assertNull($this->created->getFolder());
	}
}
