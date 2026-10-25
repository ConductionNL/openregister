<?php

/**
 * Unit tests for ImportDestructionListRegister.
 *
 * The step imports the shipped destruction-list register descriptor, whose
 * statuses cover the open ones the review reads, and never lets a failure
 * escape: it runs on install and upgrade.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/example-set-archival-setting/specs/retention-management/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Repair;

use OCA\OpenRegister\Repair\ImportDestructionListRegister;
use OCA\OpenRegister\Service\Archival\DestructionListRepository;
use OCA\OpenRegister\Service\ConfigurationService;
use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the destruction-list register import.
 */
class ImportDestructionListRegisterTest extends TestCase {

	/**
	 * The step, with the app at a path and the importer given.
	 *
	 * @param string               $appPath  Where the app is installed.
	 * @param ConfigurationService $importer The configuration importer.
	 *
	 * @return ImportDestructionListRegister
	 */
	private function step(string $appPath, ConfigurationService $importer): ImportDestructionListRegister {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->with('openregister')->willReturn($appPath);

		return new ImportDestructionListRegister(
			configurationService: $importer,
			appManager: $appManager,
			logger: $this->createMock(LoggerInterface::class)
		);

	}//end step()

	/**
	 * The shipped descriptor is imported as `destruction-lists` with its
	 * `destructionList` schema, under the step's own id, and its statuses hold
	 * every open status the pending review walks.
	 *
	 * @return void
	 */
	public function testTheShippedDescriptorIsImported(): void {
		$imported = null;
		$importer = $this->createMock(ConfigurationService::class);
		$importer->expects($this->once())
			->method('importFromApp')
			->willReturnCallback(
				static function (string $appId, array $data, string $version, bool $force) use (&$imported): array {
					$imported = ['appId' => $appId, 'data' => $data, 'version' => $version, 'force' => $force];
					return [];
				}
			);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info');

		$this->step(appPath: (string)realpath(__DIR__ . '/../../..'), importer: $importer)->run($output);

		$this->assertSame('openregister.destruction-lists', $imported['appId']);
		$this->assertSame('1.0.0', $imported['version']);
		$this->assertFalse($imported['force']);
		$this->assertSame(['destructionList'], $imported['data']['components']['registers']['destruction-lists']['schemas']);
		$schema = $imported['data']['components']['schemas']['destructionList'];
		foreach (DestructionListRepository::OPEN_STATUSES as $status) {
			$this->assertContains($status, $schema['properties']['status']['enum']);
		}

		$entry = array_keys($schema['properties']['objects']['items']['properties']);
		foreach (['uuid', 'title', 'reviewer', 'assignedAt', 'archiefactiedatum', 'classification', 'decision'] as $field) {
			$this->assertContains($field, $entry);
		}

	}//end testTheShippedDescriptorIsImported()

	/**
	 * A failing import is a warning; the exception never escapes.
	 *
	 * @return void
	 */
	public function testAFailingImportIsAWarning(): void {
		$importer = $this->createMock(ConfigurationService::class);
		$importer->method('importFromApp')->willThrowException(new RuntimeException('database is gone'));
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning')->with($this->stringContains('database is gone'));

		$this->step(appPath: (string)realpath(__DIR__ . '/../../..'), importer: $importer)->run($output);

	}//end testAFailingImportIsAWarning()

	/**
	 * A missing descriptor is a warning, not an import.
	 *
	 * @return void
	 */
	public function testAMissingDescriptorIsAWarning(): void {
		$importer = $this->createMock(ConfigurationService::class);
		$importer->expects($this->never())->method('importFromApp');
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning');

		$this->step(appPath: sys_get_temp_dir() . '/no-such-app-' . bin2hex(random_bytes(4)), importer: $importer)->run($output);

	}//end testAMissingDescriptorIsAWarning()
}//end class
