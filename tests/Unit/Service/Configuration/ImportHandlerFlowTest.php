<?php

declare(strict_types=1);

/**
 * ImportHandler Flow Import Tests
 *
 * Unit tests for how ImportHandler hands `components.flows` entries to the
 * flow engine's ConfigurationFlowTransferInterface port.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use Exception;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Kernel\Contracts\ConfigurationFlowTransferInterface;
use OCA\OpenRegister\Service\Configuration\ConfigurationImportRecords;
use OCA\OpenRegister\Service\Configuration\ImportHandler;
use OCA\OpenRegister\Service\Configuration\ImportRun;
use OCA\OpenRegister\Service\Configuration\SchemaImportSteps;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Unit tests for ImportHandler's Flow import support.
 */
class ImportHandlerFlowTest extends TestCase {

	/**
	 * A handler with mocked collaborators and no flow transfer wired.
	 */
	private function handler(): ImportHandler {
		return new ImportHandler(
			catalog: new SchemaCatalog(
				registers: $this->createMock(RegisterMapper::class),
				schemas: $this->createMock(SchemaMapper::class),
				tables: $this->createMock(MagicMapper::class)
			),
			records: new ConfigurationImportRecords(
				configurations: $this->createMock(ConfigurationMapper::class),
				mappings: $this->createMock(MappingMapper::class),
				appConfig: $this->createMock(IAppConfig::class)
			),
			schemaSteps: new SchemaImportSteps(),
			logger: $this->createMock(LoggerInterface::class),
			appDataPath: '/tmp',
			objectService: $this->createMock(ObjectService::class)
		);
	}//end handler()

	/**
	 * An import run carrying the given flow entries for a configuration of organisation org-1.
	 *
	 * @param array $flows The `components.flows` section.
	 */
	private function importRun(array $flows): ImportRun {
		$configuration = new Configuration();
		$configuration->setOrganisation('org-1');

		$run = new ImportRun(data: ['components' => ['flows' => $flows]], configuration: $configuration);
		$run->owner = 'alice';
		$run->force = true;
		$run->result = ['flows' => [], 'skipped' => ['flows' => 0]];

		return $run;
	}//end importRun()

	/**
	 * Run the private importFlowComponents() step.
	 */
	private function importFlowComponents(ImportHandler $handler, ImportRun $run): void {
		$method = (new ReflectionClass($handler))->getMethod('importFlowComponents');
		$method->setAccessible(true);
		$method->invoke($handler, $run);
	}//end importFlowComponents()

	/**
	 * Without a flow transfer wired, `components.flows` is not imported at all.
	 */
	public function testFlowsAreSkippedWhenNoTransferIsWired(): void {
		$run = $this->importRun(['flow-uuid-1' => ['name' => 'My Flow']]);

		$this->importFlowComponents(handler: $this->handler(), run: $run);

		$this->assertSame([], $run->result['flows']);
		$this->assertSame(0, $run->result['skipped']['flows']);
	}//end testFlowsAreSkippedWhenNoTransferIsWired()

	/**
	 * Each entry goes to the transfer with its key as uuid, the run's owner and force, and the configuration's organisation.
	 */
	public function testEachEntryIsImportedThroughTheTransfer(): void {
		$flow = new Flow();
		$transfer = $this->createMock(ConfigurationFlowTransferInterface::class);
		$transfer->expects($this->once())->method('importFlow')
			->with(['name' => 'My Flow', 'uuid' => 'flow-uuid-1'], 'alice', 'org-1', true)
			->willReturn($flow);

		$handler = $this->handler();
		$handler->setFlowTransfer($transfer);
		$run = $this->importRun(['flow-uuid-1' => ['name' => 'My Flow']]);

		$this->importFlowComponents(handler: $handler, run: $run);

		$this->assertSame([$flow], $run->result['flows']);
	}//end testEachEntryIsImportedThroughTheTransfer()

	/**
	 * A failing entry is counted as skipped and the next one still imports.
	 */
	public function testAFailingEntryIsCountedAsSkipped(): void {
		$flow = new Flow();
		$transfer = $this->createMock(ConfigurationFlowTransferInterface::class);
		$transfer->method('importFlow')->willReturnCallback(
			static function (array $data) use ($flow): ?object {
				if ($data['uuid'] === 'broken') {
					throw new Exception('boom');
				}

				return $flow;
			}
		);

		$handler = $this->handler();
		$handler->setFlowTransfer($transfer);
		$run = $this->importRun(['broken' => [], 'flow-uuid-1' => []]);

		$this->importFlowComponents(handler: $handler, run: $run);

		$this->assertSame(1, $run->result['skipped']['flows']);
		$this->assertSame([$flow], $run->result['flows']);
	}//end testAFailingEntryIsCountedAsSkipped()
}//end class
