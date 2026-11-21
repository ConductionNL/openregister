<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\BackgroundJob\BlobMigrationRun}.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
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

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\BlobMigrationRun;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCP\IAppConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * One blob-migration run: collaborators from the container, progress in prefixed app config.
 *
 * @covers \OCA\OpenRegister\BackgroundJob\BlobMigrationRun
 */
class BlobMigrationRunTest extends TestCase {

	/**
	 * Every collaborator is resolved from the container; counters start at zero.
	 *
	 * @return void
	 */
	public function testResolvesCollaboratorsFromContainer(): void {
		$services = [
			LoggerInterface::class         => $this->createMock(LoggerInterface::class),
			IDBConnection::class           => $this->createMock(IDBConnection::class),
			IAppConfig::class              => $this->createMock(IAppConfig::class),
			RegisterMapperInterface::class => $this->createMock(RegisterMapperInterface::class),
			SchemaMapperInterface::class   => $this->createMock(SchemaMapperInterface::class),
			MagicMapperInterface::class    => $this->createMock(MagicMapperInterface::class),
		];
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->exactly(6))->method('get')->willReturnCallback(static fn (string $id) => $services[$id]);

		$before = microtime(true);
		$run    = new BlobMigrationRun($container);

		$this->assertSame($services[LoggerInterface::class], $run->logger);
		$this->assertSame($services[IDBConnection::class], $run->db);
		$this->assertSame($services[IAppConfig::class], $run->appConfig);
		$this->assertSame($services[RegisterMapperInterface::class], $run->registerMapper);
		$this->assertSame($services[SchemaMapperInterface::class], $run->schemaMapper);
		$this->assertSame($services[MagicMapperInterface::class], $run->magicMapper);
		$this->assertSame(0, $run->migrated);
		$this->assertSame(0, $run->skipped);
		$this->assertGreaterThanOrEqual($before, $run->startTime);

	}//end testResolvesCollaboratorsFromContainer()

	/**
	 * Config reads and writes go to the openregister app under the blob_migration_ prefix.
	 *
	 * @return void
	 */
	public function testConfigIsPrefixed(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects($this->once())->method('getValueString')
			->with('openregister', 'blob_migration_cursor', '0')
			->willReturn('42');
		$config->expects($this->once())->method('setValueString')
			->with('openregister', 'blob_migration_cursor', '43');

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id) => $id === IAppConfig::class ? $config : $this->createMock($id)
		);

		$run = new BlobMigrationRun($container);

		$this->assertSame('42', $run->config('cursor', '0'));
		$run->setConfig('cursor', '43');

	}//end testConfigIsPrefixed()
}//end class
