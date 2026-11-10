<?php

/**
 * SeedFlowTimerRegister refuses a descriptor that is not JSON without importing.
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

use OCA\OpenRegister\Repair\SeedFlowTimerRegister;
use OCA\OpenRegister\Repair\RegisterDescriptorImport;
use OCA\OpenRegister\Service\AppConfigurationImporterInterface;
use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The invalid-JSON branch, against a throwaway app root on disk.
 */
class SeedFlowTimerRegisterInvalidDescriptorTest extends TestCase {

	private string $root;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/or-flow-timer-' . bin2hex(random_bytes(4));
		mkdir($this->root . '/lib/Settings', 0777, true);
	}

	protected function tearDown(): void {
		@unlink($this->root . SeedFlowTimerRegister::REGISTER_PATH);
		@rmdir($this->root . '/lib/Settings');
		@rmdir($this->root . '/lib');
		@rmdir($this->root);
	}

	public function testADescriptorThatIsNotJsonIsWarnedAboutAndNotImported(): void {
		file_put_contents($this->root . SeedFlowTimerRegister::REGISTER_PATH, '{ not json');
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppPath')->with('openregister')->willReturn($this->root);
		$importer = $this->createMock(AppConfigurationImporterInterface::class);
		$importer->expects($this->never())->method('importFromApp');
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning')
			->with('Flow-timers register descriptor is not valid JSON: ' . $this->root . SeedFlowTimerRegister::REGISTER_PATH);
		$output->expects($this->never())->method('info');

		(new SeedFlowTimerRegister(new RegisterDescriptorImport($importer, $apps, $this->createMock(LoggerInterface::class))))->run($output);
	}

	public function testAValidDescriptorIsImportedWithoutForce(): void {
		file_put_contents($this->root . SeedFlowTimerRegister::REGISTER_PATH, '{"openapi":"3.0.0"}');
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppPath')->willReturn($this->root);
		$importer = $this->createMock(AppConfigurationImporterInterface::class);
		$importer->expects($this->once())->method('importFromApp')
			->with('openregister', ['openapi' => '3.0.0'], '1.2.0', false);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->never())->method('warning');

		(new SeedFlowTimerRegister(new RegisterDescriptorImport($importer, $apps, $this->createMock(LoggerInterface::class))))->run($output);
	}
}
