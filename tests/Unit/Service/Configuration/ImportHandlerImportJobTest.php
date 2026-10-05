<?php

/**
 * Tests that importFromApp() runs under its own import job id.
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
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/data-import-export/spec.md#requirement-an-app-configuration-import-must-run-under-its-own-import-job-id
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use Exception;
use GuzzleHttp\Client;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Configuration\AppImportJobRecorder;
use OCA\OpenRegister\Service\Configuration\ImportHandler;
use OCA\OpenRegister\Service\Configuration\UploadHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Configuration\ImportHandler
 * @uses \OCA\OpenRegister\Db\Configuration
 */
class ImportHandlerImportJobTest extends TestCase {
	/**
	 * Configuration mapper double.
	 *
	 * @var ConfigurationMapper&MockObject
	 */
	private ConfigurationMapper&MockObject $configurationMapper;

	/**
	 * App config double.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Recorder double.
	 *
	 * @var AppImportJobRecorder&MockObject
	 */
	private AppImportJobRecorder&MockObject $recorder;

	/**
	 * Wire the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->configurationMapper = $this->createMock(ConfigurationMapper::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->recorder = $this->createMock(AppImportJobRecorder::class);

		$config = new Configuration();
		$config->setApp('learniq.demo');
		$config->setVersion('0.1.0');
		$config->setRegisters([]);
		$config->setSchemas([]);
		$config->setObjects([]);
		$ref = new ReflectionClass($config);
		$prop = $ref->getProperty('id');
		$prop->setAccessible(true);
		$prop->setValue($config, 7);

		$this->configurationMapper->method('findBySourceUrl')->willReturn(null);
		$this->configurationMapper->method('findByApp')->willReturn([$config]);
		$this->configurationMapper->method('update')->willReturnArgument(0);
	}

	/**
	 * The handler under test.
	 *
	 * @param AppImportJobRecorder|null $recorder The recorder, or null for an untagged import.
	 *
	 * @return ImportHandler
	 */
	private function handler(?AppImportJobRecorder $recorder): ImportHandler {
		return new ImportHandler(
			schemaMapper: $this->createMock(SchemaMapper::class),
			registerMapper: $this->createMock(RegisterMapper::class),
			objectEntityMapper: $this->createMock(MagicMapper::class),
			configurationMapper: $this->configurationMapper,
			mappingMapper: $this->createMock(MappingMapper::class),
			client: $this->createMock(Client::class),
			appConfig: $this->appConfig,
			logger: $this->createMock(LoggerInterface::class),
			appDataPath: '/tmp',
			uploadHandler: $this->createMock(UploadHandler::class),
			objectService: $this->createMock(ObjectService::class),
			importJobRecorder: $recorder
		);
	}

	/**
	 * The import runs between begin() and end(), then is recorded and returns its id.
	 *
	 * @return void
	 */
	public function testImportFromAppStampsTheImportAndClearsTheStamp(): void {
		$calls = [];
		$this->appConfig->method('getValueString')->willReturnCallback(
			function () use (&$calls): string {
				$calls[] = 'import';
				return '';
			}
		);
		$this->recorder->expects($this->once())->method('begin')->willReturnCallback(
			function () use (&$calls): string {
				$calls[] = 'begin';
				return 'job-1';
			}
		);
		$this->recorder->expects($this->once())->method('end')->willReturnCallback(
			function () use (&$calls): void {
				$calls[] = 'end';
			}
		);
		$this->recorder->expects($this->once())
			->method('record')
			->with('learniq.demo', 'job-1', '0.2.0', 0)
			->willReturn(true);

		$result = $this->handler($this->recorder)->importFromApp('learniq.demo', ['components' => []], '0.2.0');

		$this->assertSame('job-1', $result['importJobId']);
		$this->assertSame('begin', $calls[0]);
		$this->assertSame('end', $calls[count($calls) - 1]);
		$this->assertContains('import', $calls, 'The import itself ran between begin() and end().');
	}

	/**
	 * A job that recorded nothing returns a null importJobId.
	 *
	 * @return void
	 */
	public function testAnUnrecordedJobReturnsANullImportJobId(): void {
		$this->appConfig->method('getValueString')->willReturn('');
		$this->recorder->method('begin')->willReturn('job-2');
		$this->recorder->method('record')->willReturn(false);

		$result = $this->handler($this->recorder)->importFromApp('learniq.demo', ['components' => []], '0.2.0');

		$this->assertNull($result['importJobId']);
	}

	/**
	 * A throwing import still ends the stamp, and is not recorded.
	 *
	 * @return void
	 */
	public function testTheStampIsClearedWhenTheImportThrows(): void {
		$this->appConfig->method('getValueString')->willThrowException(new RuntimeException('database gone'));
		$this->recorder->method('begin')->willReturn('job-3');
		$this->recorder->expects($this->once())->method('end');
		$this->recorder->expects($this->never())->method('record');

		$this->expectException(Exception::class);

		$this->handler($this->recorder)->importFromApp('learniq.demo', ['components' => []], '0.2.0');
	}

	/**
	 * Without a recorder the import runs untagged, as before this change.
	 *
	 * @return void
	 */
	public function testWithoutARecorderTheImportRunsUntagged(): void {
		$this->appConfig->method('getValueString')->willReturn('');

		$result = $this->handler(null)->importFromApp('learniq.demo', ['components' => []], '0.2.0');

		$this->assertArrayHasKey('importJobId', $result);
		$this->assertNull($result['importJobId']);
	}
}
