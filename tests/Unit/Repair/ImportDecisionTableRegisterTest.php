<?php

/**
 * Unit tests for ImportDecisionTableRegister.
 *
 * The step imports the shipped decision-table register descriptor, and it
 * never lets a failure escape: it runs on install and upgrade, where an escaping
 * exception aborts the app.
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
 * @spec openspec/changes/stored-decision-tables/specs/flow-decision-tables/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Repair;

use OCA\OpenRegister\Repair\ImportDecisionTableRegister;
use OCA\OpenRegister\Service\ConfigurationService;
use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the decision-table register import.
 */
class ImportDecisionTableRegisterTest extends TestCase {

	/**
	 * @var ConfigurationService&MockObject
	 */
	private ConfigurationService&MockObject $configurationService;

	/**
	 * @var IAppManager&MockObject
	 */
	private IAppManager&MockObject $appManager;

	/**
	 * @var IOutput&MockObject
	 */
	private IOutput&MockObject $output;

	/**
	 * Build the step with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->configurationService = $this->createMock(ConfigurationService::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->output = $this->createMock(IOutput::class);

	}//end setUp()

	/**
	 * The step, with the app living at a path.
	 *
	 * @param string $appPath Where the app is installed.
	 *
	 * @return ImportDecisionTableRegister
	 */
	private function step(string $appPath): ImportDecisionTableRegister {
		$this->appManager->method('getAppPath')->with('openregister')->willReturn($appPath);

		return new ImportDecisionTableRegister(
			configurationService: $this->configurationService,
			appManager: $this->appManager,
			logger: $this->createMock(LoggerInterface::class)
		);

	}//end step()

	/**
	 * The shipped descriptor is imported as the decision-tables register with
	 * its `decision-table` schema, under the step's own configuration id.
	 *
	 * @return void
	 */
	public function testTheShippedDescriptorIsImported(): void {
		$this->configurationService->expects($this->once())
			->method('importFromApp')
			->with(
				$this->equalTo('openregister.decision-tables'),
				$this->callback(
					static function (array $data): bool {
						return isset($data['components']['registers']['decision-tables']) === true
							&& ($data['components']['schemas']['decision-table']['slug'] ?? null) === 'decision-table';
					}
				),
				$this->equalTo('1.0.0'),
				$this->equalTo(false)
			);
		$this->output->expects($this->once())->method('info');

		$step = $this->step(appPath: (string)realpath(__DIR__ . '/../../..'));
		$step->run($this->output);

		$this->assertStringContainsString('decision-table', $step->getName());

	}//end testTheShippedDescriptorIsImported()

	/**
	 * A missing descriptor is a warning, not an import.
	 *
	 * @return void
	 */
	public function testAMissingDescriptorWarnsAndImportsNothing(): void {
		$this->configurationService->expects($this->never())->method('importFromApp');
		$this->output->expects($this->once())->method('warning')
			->with($this->stringContains('not found'));

		$this->step(appPath: sys_get_temp_dir() . '/no-such-openregister-' . getmypid())->run($this->output);

	}//end testAMissingDescriptorWarnsAndImportsNothing()

	/**
	 * A descriptor that is not JSON is a warning, not an import.
	 *
	 * @return void
	 */
	public function testADescriptorThatIsNotJsonWarns(): void {
		$root = sys_get_temp_dir() . '/or-dt-' . getmypid() . '-' . bin2hex(random_bytes(4));
		mkdir($root . '/lib/Settings', 0777, true);
		file_put_contents($root . '/lib/Settings/decision_table_register.json', '{not json');

		$this->configurationService->expects($this->never())->method('importFromApp');
		$this->output->expects($this->once())->method('warning')
			->with($this->stringContains('not valid JSON'));

		try {
			$this->step(appPath: $root)->run($this->output);
		} finally {
			unlink($root . '/lib/Settings/decision_table_register.json');
			rmdir($root . '/lib/Settings');
			rmdir($root . '/lib');
			rmdir($root);
		}

	}//end testADescriptorThatIsNotJsonWarns()

	/**
	 * A failing import is caught and reported, never thrown.
	 *
	 * @return void
	 */
	public function testAFailingImportIsReportedNotThrown(): void {
		$this->configurationService->method('importFromApp')->willThrowException(new RuntimeException('database gone'));
		$this->output->expects($this->once())->method('warning')
			->with($this->stringContains('database gone'));

		$this->step(appPath: (string)realpath(__DIR__ . '/../../..'))->run($this->output);

	}//end testAFailingImportIsReportedNotThrown()
}//end class
