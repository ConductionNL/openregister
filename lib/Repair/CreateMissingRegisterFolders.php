<?php

/**
 * CreateMissingRegisterFolders: give every register its Files folder.
 *
 * App configuration imports now provision a register's folder when they create
 * it (register-folder-at-import). Registers imported before that have none
 * until their first upload makes one. This step runs the same provisioning over
 * every register on the instance, across organisations, on upgrade. The id is
 * recorded as bookkeeping through RegisterFolderRecorder, so no register-updated
 * event fires and no organisation check applies. It never throws: a folder it
 * cannot make is reported and left for the first upload.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/file-actions/spec.md#requirement-a-repair-step-provisions-folders-for-registers-imported-earlier-req-rfai-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Files\Service\File\RegisterFolderProvisionerInterface;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Repair step: provision the Files folder of every register that lacks one.
 *
 * @spec openspec/specs/file-actions/spec.md#requirement-a-repair-step-provisions-folders-for-registers-imported-earlier-req-rfai-002
 */
class CreateMissingRegisterFolders implements IRepairStep {

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container; the mapper and the provisioner
	 *                                      are resolved lazily, as the other repair steps
	 *                                      do, so a half-wired boot skips instead of failing.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Step name shown by occ.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/file-actions/spec.md#requirement-a-repair-step-provisions-folders-for-registers-imported-earlier-req-rfai-002
	 */
	public function getName(): string {
		return 'Create the Files folder of registers that have none';
	}//end getName()

	/**
	 * Provision every register's folder and report the tally.
	 *
	 * @param IOutput $output Migration output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/file-actions/spec.md#requirement-a-repair-step-provisions-folders-for-registers-imported-earlier-req-rfai-002
	 */
	public function run(IOutput $output): void {
		try {
			$registerMapper = $this->container->get(RegisterMapperInterface::class);
			$provisioner = $this->container->get(RegisterFolderProvisionerInterface::class);
			// Every register, whatever organisation owns it: occ upgrade has no
			// active organisation, and the write is bookkeeping, not an edit.
			$registers = $registerMapper->findAll(_rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$this->reportSkipped(error: $e, output: $output);
			return;
		}

		$tally = $provisioner->ensureFolders(registers: $registers);

		$output->info(self::tallyLine(tally: $tally));
	}//end run()

	/**
	 * Log and print that the step was skipped because its services are unavailable.
	 *
	 * @param Throwable $error  Why the services could not be resolved.
	 * @param IOutput   $output Migration output handle.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/file-actions/spec.md#requirement-a-repair-step-provisions-folders-for-registers-imported-earlier-req-rfai-002
	 */
	private function reportSkipped(Throwable $error, IOutput $output): void {
		$this->logger->info(
			message: '[CreateMissingRegisterFolders] Skipped: ' . $error->getMessage(),
			context: ['file' => __FILE__, 'line' => __LINE__]
		);
		$output->info('Register folders: skipped, services unavailable (' . $error->getMessage() . ')');
	}//end reportSkipped()

	/**
	 * The closing line: folders provisioned, already present and not made.
	 *
	 * @param array{provisioned: int, present: int, failed: int} $tally The provisioner's tally.
	 *
	 * @return string The line.
	 *
	 * @spec openspec/specs/file-actions/spec.md#requirement-a-repair-step-provisions-folders-for-registers-imported-earlier-req-rfai-002
	 */
	private static function tallyLine(array $tally): string {
		return sprintf(
			'Register folders: %d provisioned, %d already present, %d could not be made',
			$tally['provisioned'],
			$tally['present'],
			$tally['failed']
		);
	}//end tallyLine()
}//end class
