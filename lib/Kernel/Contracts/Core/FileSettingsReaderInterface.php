<?php
/**
 * FileSettingsReaderInterface: the stored file management settings, as a read returns them.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Core
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Core;

/**
 * Reads the file management settings, with their defaults filled in.
 *
 * File handling and the areas above Core ask; the file settings handler
 * (Configuration) answers and is bound to this contract.
 */
interface FileSettingsReaderInterface {

	/**
	 * The file management settings; the password is never part of it, only whether one is set.
	 *
	 * @return array File management configuration.
	 *
	 * @throws \RuntimeException If File Management settings retrieval fails.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid1/tasks.md#task-1
	 */
	public function getFileSettingsOnly(): array;
}//end interface
