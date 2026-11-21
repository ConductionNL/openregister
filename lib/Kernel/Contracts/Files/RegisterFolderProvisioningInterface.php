<?php

/**
 * RegisterFolderProvisioningInterface: every register gets its files folder.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Files
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Files;

/**
 * Ensures every register has its files folder, as the import and the repair step run it.
 *
 * Files answers (RegisterFolderProvisioner, through RegisterFolderProvisionerInterface).
 *
 * @spec openspec/specs/file-actions/spec.md#requirement-a-repair-step-provisions-folders-for-registers-imported-earlier-req-rfai-002
 */
interface RegisterFolderProvisioningInterface {

	/**
	 * Ensure a folder for every register in the list.
	 *
	 * @param iterable<mixed> $registers The registers to provision.
	 *
	 * @return array{provisioned: int, present: int, failed: int} How many got a new folder id,
	 *                                                            already had one that resolves, or could not get one.
	 *
	 * @spec openspec/specs/file-actions/spec.md#requirement-an-app-imported-register-has-its-files-folder-when-the-import-returns-req-rfai-001
	 */
	public function ensureFolders(iterable $registers): array;
}//end interface
