<?php

/**
 * Unit tests for SeedIntakeSourceRegister: descriptor guards, import call and failure reporting.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Repair;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

use OCA\OpenRegister\Repair\SeedIntakeSourceRegister;
use OCA\OpenRegister\Repair\RegisterDescriptorImport;
use OCA\OpenRegister\Service\AppConfigurationImporterInterface;
use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Repair\SeedIntakeSourceRegister
 */
final class SeedIntakeSourceRegisterTest extends TestCase {

	/** @var AppConfigurationImporterInterface&MockObject */
	private AppConfigurationImporterInterface $importer;

	/** @var IAppManager&MockObject */
	private IAppManager $appManager;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	private string $root;

	protected function setUp(): void {
		parent::setUp();
		$this->importer = $this->createMock(AppConfigurationImporterInterface::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->root = sys_get_temp_dir() . '/intake-' . bin2hex(random_bytes(4));
		mkdir($this->root . '/lib/Settings', 0777, true);
		$this->appManager->method('getAppPath')->with('openregister')->willReturn($this->root);
	}

	protected function tearDown(): void {
		@unlink($this->root . SeedIntakeSourceRegister::REGISTER_PATH);
		rmdir($this->root . '/lib/Settings');
		rmdir($this->root . '/lib');
		rmdir($this->root);
		parent::tearDown();
	}

	private function step(): SeedIntakeSourceRegister {
		return new SeedIntakeSourceRegister(new RegisterDescriptorImport($this->importer, $this->appManager, $this->logger));
	}

	public function testNameNamesTheStep(): void {
		$this->assertSame('Seed the intake-sources register (one object per watched folder, mailbox or endpoint)', $this->step()->getName());
	}

	public function testTheShippedDescriptorIsImportedWithoutForce(): void {
		file_put_contents($this->root . SeedIntakeSourceRegister::REGISTER_PATH, '{"components":{"schemas":{}}}');
		$this->importer->expects($this->once())->method('importFromApp')
			->with('openregister', ['components' => ['schemas' => []]], '1.0.0', false);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with('Intake-sources register imported (intake-source schema)');
		$output->expects($this->never())->method('warning');

		$this->step()->run($output);
	}

	public function testAMissingDescriptorWarnsAndImportsNothing(): void {
		$this->importer->expects($this->never())->method('importFromApp');
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning')
			->with('Intake-sources register descriptor not found: ' . $this->root . SeedIntakeSourceRegister::REGISTER_PATH);

		$this->step()->run($output);
	}

	public function testAnInvalidDescriptorWarnsAndImportsNothing(): void {
		file_put_contents($this->root . SeedIntakeSourceRegister::REGISTER_PATH, 'not json');
		$this->importer->expects($this->never())->method('importFromApp');
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning')
			->with('Intake-sources register descriptor is not valid JSON: ' . $this->root . SeedIntakeSourceRegister::REGISTER_PATH);

		$this->step()->run($output);
	}

	public function testAnImportFailureIsLoggedAndReported(): void {
		file_put_contents($this->root . SeedIntakeSourceRegister::REGISTER_PATH, '{}');
		$this->importer->method('importFromApp')->willThrowException(new RuntimeException('schema clash'));
		$this->logger->expects($this->once())->method('warning')->with('[SeedIntakeSourceRegister] import failed: schema clash');
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning')->with('Intake-sources register import skipped: schema clash');

		$this->step()->run($output);
	}
}
