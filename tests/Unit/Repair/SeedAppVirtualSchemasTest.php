<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Repair\SeedAppVirtualSchemas}.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-2.2
 */

declare(strict_types=1);

namespace Unit\Repair;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Repair\SeedAppVirtualSchemas;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins what the app virtual schema seed creates, links and swallows.
 */
class SeedAppVirtualSchemasTest extends TestCase {

	/**
	 * The register mapper double.
	 *
	 * @var RegisterMapperInterface&MockObject
	 */
	private $registerMapper;

	/**
	 * The schema mapper double.
	 *
	 * @var SchemaMapperInterface&MockObject
	 */
	private $schemaMapper;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private $logger;

	/**
	 * Wire fresh doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->registerMapper = $this->createMock(RegisterMapperInterface::class);
		$this->schemaMapper = $this->createMock(SchemaMapperInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * A schema catalog serving the current mapper doubles.
	 *
	 * @return SchemaCatalogInterface The catalog.
	 */
	private function catalog(): SchemaCatalogInterface {
		$catalog = $this->createMock(SchemaCatalogInterface::class);
		$catalog->method('registers')->willReturn($this->registerMapper);
		$catalog->method('schemas')->willReturn($this->schemaMapper);
		return $catalog;
	}//end catalog()

	/**
	 * Build the repair step over the current doubles.
	 *
	 * @return SeedAppVirtualSchemas The step under test.
	 */
	private function step(): SeedAppVirtualSchemas {
		return new SeedAppVirtualSchemas(
			catalog: $this->catalog(),
			logger: $this->logger
		);
	}//end step()

	/**
	 * A fresh instance gets every app register created, every schema created,
	 * and each register linked to its schemas; directory and tables rows are skipped.
	 *
	 * @return void
	 */
	public function testFreshInstanceCreatesRegistersAndSchemasAndLinksThem(): void {
		$this->registerMapper->method('find')->willThrowException(new RuntimeException('not found'));
		$this->schemaMapper->method('find')->willThrowException(new RuntimeException('not found'));

		$createdRegisters = [];
		$this->registerMapper->method('createFromArray')->willReturnCallback(
			function (array $object) use (&$createdRegisters): Register {
				$register = new Register();
				$register->setSlug($object['slug']);
				$register->setSchemas($object['schemas']);
				$createdRegisters[] = $object['slug'];
				return $register;
			}
		);

		$createdSchemas = [];
		$this->schemaMapper->method('createFromArray')->willReturnCallback(
			function (array $object) use (&$createdSchemas): Schema {
				$schema = new Schema();
				$schema->setId(100 + count($createdSchemas));
				$schema->setSlug($object['slug']);
				$createdSchemas[$object['slug']] = $object;
				return $schema;
			}
		);

		$updated = [];
		$this->registerMapper->method('update')->willReturnCallback(
			function (Register $register) use (&$updated): Register {
				$updated[$register->getSlug()] = $register->getSchemas();
				return $register;
			}
		);

		$this->logger->expects($this->never())->method('warning');

		$this->step()->run($this->createMock(IOutput::class));

		$this->assertSame(['contacts', 'calendar', 'files', 'deck', 'talk', 'tasks'], $createdRegisters);
		$this->assertNotContains('directory', $createdRegisters);
		$this->assertNotContains('tables', $createdRegisters);
		$this->assertSame($createdRegisters, array_keys($updated));
		$this->assertTrue($createdSchemas['nc-task']['configuration']['x-openregister-object-source']['config']['unscoped']);
		$this->assertTrue($createdSchemas['nc-contact']['configuration']['x-openregister-object-source']['readOnly']);
	}//end testFreshInstanceCreatesRegistersAndSchemasAndLinksThem()

	/**
	 * A register that already lists every schema is not updated again.
	 *
	 * @return void
	 */
	public function testAlreadyLinkedRegisterIsNotUpdated(): void {
		$schema = new Schema();
		$schema->setId(7);
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->registerMapper->method('find')->willReturnCallback(
			function (): Register {
				$register = new Register();
				$register->setSchemas([7]);
				return $register;
			}
		);

		$this->registerMapper->expects($this->never())->method('createFromArray');
		$this->schemaMapper->expects($this->never())->method('createFromArray');
		$this->registerMapper->expects($this->never())->method('update');

		$this->step()->run($this->createMock(IOutput::class));
	}//end testAlreadyLinkedRegisterIsNotUpdated()

	/**
	 * A failing seed logs a warning and does not throw.
	 *
	 * @return void
	 */
	public function testFailureIsLoggedAndSwallowed(): void {
		$this->registerMapper->method('find')->willThrowException(new RuntimeException('not found'));
		$this->registerMapper->method('createFromArray')->willThrowException(new RuntimeException('db down'));

		$this->logger->expects($this->once())->method('warning')->with($this->stringContains('db down'));
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning');

		$this->step()->run($output);
	}//end testFailureIsLoggedAndSwallowed()
}//end class
