<?php

/**
 * Tests for the CreateMissingRegisterFolders repair step.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/register-folder-at-import/specs/file-actions/spec.md#requirement-a-repair-step-provisions-folders-for-registers-imported-earlier-req-rfai-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Repair;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Repair\CreateMissingRegisterFolders;
use OCA\OpenRegister\Service\File\RegisterFolderProvisioner;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Repair\CreateMissingRegisterFolders
 */
class CreateMissingRegisterFoldersTest extends TestCase {

	/**
	 * Every register is read across organisations and the tally is reported.
	 *
	 * @return void
	 */
	public function testProvisionsEveryRegisterAcrossOrganisationsAndReports(): void {
		$registers = [new Register(), new Register()];

		$mapper = $this->createMock(RegisterMapper::class);
		$mapper->expects($this->once())
			->method('findAll')
			->with(null, null, [], [], [], false, false)
			->willReturn($registers);

		$provisioner = $this->createMock(RegisterFolderProvisioner::class);
		$provisioner->expects($this->once())
			->method('ensureFolders')
			->with($registers)
			->willReturn(['provisioned' => 1, 'present' => 1, 'failed' => 0]);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap(
			[
				[RegisterMapper::class, $mapper],
				[RegisterFolderProvisioner::class, $provisioner],
			]
		);

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())
			->method('info')
			->with('Register folders: 1 provisioned, 1 already present, 0 could not be made');

		$step = new CreateMissingRegisterFolders($container, $this->createMock(LoggerInterface::class));
		$step->run($output);

		$this->assertNotSame('', $step->getName());
	}

	/**
	 * A container that cannot build the services makes the step skip, not throw.
	 *
	 * @return void
	 */
	public function testSkipsWhenServicesAreUnavailable(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('not wired'));

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())
			->method('info')
			->with($this->stringContains('skipped'));

		(new CreateMissingRegisterFolders($container, $this->createMock(LoggerInterface::class)))->run($output);
	}
}
