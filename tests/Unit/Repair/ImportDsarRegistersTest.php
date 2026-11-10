<?php

/**
 * The repair step that imports the DSAR registers.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Repair;

use OCA\OpenRegister\Repair\RegisterDescriptorImport;
use OCA\OpenRegister\Repair\ImportDsarRegisters;
use OCA\OpenRegister\Service\AppConfigurationImporterInterface;
use OCA\OpenRegister\Service\Configuration\DescriptorConfigIdsInterface;
use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Each shipped descriptor is imported under its own config id and version; a missing,
 * malformed or failing descriptor is skipped with a warning and never stops the other.
 *
 * @covers \OCA\OpenRegister\Repair\ImportDsarRegisters
 */
final class ImportDsarRegistersTest extends TestCase {

	private string $appPath;

	private AppConfigurationImporterInterface&MockObject $importer;

	private LoggerInterface&MockObject $logger;

	private IOutput&MockObject $output;

	/**
	 * A throwaway app directory per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->appPath = sys_get_temp_dir() . '/or-dsar-' . bin2hex(random_bytes(6));
		mkdir($this->appPath . '/lib/Settings', 0777, true);
		$this->importer = $this->createMock(AppConfigurationImporterInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->output = $this->createMock(IOutput::class);
	}//end setUp()

	/**
	 * Remove the throwaway directory.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach (glob($this->appPath . '/lib/Settings/*') as $file) {
			unlink($file);
		}

		rmdir($this->appPath . '/lib/Settings');
		rmdir($this->appPath . '/lib');
		rmdir($this->appPath);
		parent::tearDown();
	}//end tearDown()

	/**
	 * Write a descriptor file.
	 *
	 * @param string $name The file name.
	 * @param string $contents The contents.
	 *
	 * @return void
	 */
	private function write(string $name, string $contents): void {
		file_put_contents($this->appPath . '/lib/Settings/' . $name, $contents);
	}//end write()

	/**
	 * The repair step under test.
	 *
	 * @return ImportDsarRegisters The step.
	 */
	private function step(): ImportDsarRegisters {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppPath')->with('openregister')->willReturn($this->appPath);
		return new ImportDsarRegisters(
			import: new RegisterDescriptorImport(configurationService: $this->importer, appManager: $apps, logger: $this->logger)
		);
	}//end step()

	/**
	 * Both descriptors are imported, unforced, under their own id and version.
	 *
	 * @return void
	 */
	public function testBothDescriptorsAreImportedWithTheirIdAndVersion(): void {
		$this->write('data_subject_request_register.json', '{"cases": true}');
		$this->write('dsar_policy_pack_register.json', '{"packs": true}');
		$calls = [];
		$this->importer->expects($this->exactly(2))->method('importFromApp')->willReturnCallback(
			static function (string $appId, array $data, string $version, bool $force) use (&$calls): array {
				$calls[] = [$appId, $data, $version, $force];
				return [];
			}
		);
		$infos = [];
		$this->output->method('info')->willReturnCallback(static function (string $message) use (&$infos): void {
			$infos[] = $message;
		});
		$this->output->expects($this->never())->method('warning');

		$this->step()->run($this->output);

		$this->assertSame(
			[
				[DescriptorConfigIdsInterface::DSAR_CASES, ['cases' => true], '1.2.0', false],
				[DescriptorConfigIdsInterface::DSAR_POLICY_PACKS, ['packs' => true], '1.1.0', false],
			],
			$calls
		);
		$this->assertSame(
			[
				'DSAR register imported: data_subject_request_register.json (v1.2.0)',
				'DSAR register imported: dsar_policy_pack_register.json (v1.1.0)',
			],
			$infos
		);
	}//end testBothDescriptorsAreImportedWithTheirIdAndVersion()

	/**
	 * A missing and a malformed descriptor are each skipped with their own warning.
	 *
	 * @return void
	 */
	public function testMissingAndMalformedDescriptorsAreSkipped(): void {
		$this->write('dsar_policy_pack_register.json', 'not json');
		$this->importer->expects($this->never())->method('importFromApp');
		$warnings = [];
		$this->output->method('warning')->willReturnCallback(static function (string $message) use (&$warnings): void {
			$warnings[] = $message;
		});

		$this->step()->run($this->output);

		$this->assertCount(2, $warnings);
		$this->assertStringStartsWith('DSAR register descriptor not found: ', $warnings[0]);
		$this->assertStringEndsWith('data_subject_request_register.json', $warnings[0]);
		$this->assertStringStartsWith('DSAR register descriptor is not valid JSON: ', $warnings[1]);
	}//end testMissingAndMalformedDescriptorsAreSkipped()

	/**
	 * A failing import is logged and skipped, and the next descriptor is still imported.
	 *
	 * @return void
	 */
	public function testAFailingImportDoesNotStopTheNext(): void {
		$this->write('data_subject_request_register.json', '{"cases": true}');
		$this->write('dsar_policy_pack_register.json', '{"packs": true}');
		$this->importer->expects($this->exactly(2))->method('importFromApp')->willReturnOnConsecutiveCalls(
			$this->throwException(new RuntimeException('schema clash')),
			[]
		);
		$this->logger->expects($this->once())->method('warning')
			->with('[ImportDsarRegisters] import failed for /lib/Settings/data_subject_request_register.json: schema clash');
		$this->output->expects($this->once())->method('warning')
			->with('DSAR register import skipped for data_subject_request_register.json: schema clash');
		$this->output->expects($this->once())->method('info')
			->with('DSAR register imported: dsar_policy_pack_register.json (v1.1.0)');

		$this->step()->run($this->output);
	}//end testAFailingImportDoesNotStopTheNext()

	/**
	 * The step names what it imports.
	 *
	 * @return void
	 */
	public function testTheStepNamesWhatItImports(): void {
		$this->assertSame('Import OpenRegister DSAR registers (data-subject requests + policy packs)', $this->step()->getName());
	}//end testTheStepNamesWhatItImports()
}//end class
