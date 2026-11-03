<?php

/**
 * OpenRegister register folder provisioner
 *
 * Ensures registers have their Files folder outside the API create path: after
 * an app configuration import, and from the repair step for registers imported
 * before imports did this. The folder id is recorded through
 * FolderManagementHandler::createRegisterFolderById(), which writes it with
 * RegisterFolderRecorder: one column, no register-updated event, no organisation
 * check (register-folder-at-import, portaliq#29 option 1).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/file-actions/spec.md#requirement-an-app-imported-register-has-its-files-folder-when-the-import-returns-req-rfai-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Service\FileService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Ensures a Files folder for each register it is given, without ever throwing.
 *
 * @spec openspec/specs/file-actions/spec.md#requirement-an-app-imported-register-has-its-files-folder-when-the-import-returns-req-rfai-001
 */
class RegisterFolderProvisioner {

	/**
	 * Constructor.
	 *
	 * @param FileService $fileService File service facade whose createEntityFolder() finds or makes the folder.
	 * @param LoggerInterface $logger Logger for the tally and for failures.
	 */
	public function __construct(
		private readonly FileService $fileService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Ensure a folder for every register in the list.
	 *
	 * Entries that are not a persisted Register are skipped. Each register is
	 * guarded on its own, so one failure never stops the rest or its caller.
	 *
	 * @param iterable<mixed> $registers The registers to provision.
	 *
	 * @return array{provisioned: int, present: int, failed: int} How many got a new folder id,
	 *                                                            already had one that resolves, or could not get one.
	 *
	 * @spec openspec/specs/file-actions/spec.md#requirement-an-app-imported-register-has-its-files-folder-when-the-import-returns-req-rfai-001
	 */
	public function ensureFolders(iterable $registers): array {
		$tally = ['provisioned' => 0, 'present' => 0, 'failed' => 0];

		foreach ($registers as $register) {
			if ($register instanceof Register === false || $register->getId() === null) {
				continue;
			}

			$tally[$this->ensureFolder(register: $register)]++;
		}

		if ($tally['provisioned'] > 0) {
			$this->logger->info(
				message: sprintf(
					'[RegisterFolderProvisioner] Provisioned %d register folder(s); %d already present, %d failed',
					$tally['provisioned'],
					$tally['present'],
					$tally['failed']
				),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
		}

		return $tally;
	}//end ensureFolders()

	/**
	 * Ensure one register's folder and say what happened.
	 *
	 * @param Register $register The register to provision.
	 *
	 * @return string One of 'provisioned', 'present' or 'failed'.
	 *
	 * @psalm-return 'provisioned'|'present'|'failed'
	 */
	private function ensureFolder(Register $register): string {
		$before = (string)($register->getFolder() ?? '');

		try {
			$folder = $this->fileService->createEntityFolder($register);
		} catch (Throwable $e) {
			$this->logFailure(register: $register, reason: $e->getMessage());
			return 'failed';
		}

		if ($folder === null) {
			$this->logFailure(register: $register, reason: 'no folder was returned');
			return 'failed';
		}

		if ((string)$folder->getId() === $before) {
			return 'present';
		}

		return 'provisioned';
	}//end ensureFolder()

	/**
	 * Log a register whose folder could not be made; the first upload will make it.
	 *
	 * @param Register $register The register.
	 * @param string $reason Why it failed.
	 *
	 * @return void
	 */
	private function logFailure(Register $register, string $reason): void {
		$this->logger->warning(
			message: '[RegisterFolderProvisioner] Could not provision the folder of register {registerId}: {reason}',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'registerId' => $register->getId(),
				'reason' => $reason,
			]
		);
	}//end logFailure()
}//end class
