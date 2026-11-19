<?php
/**
 * FileAccountInterface: openregister's own file account, as the file handlers ask it.
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

use OCP\Files\File;
use OCP\IUser;

/**
 * The account openregister keeps object files under, which the file handlers (Core) read files as
 * and move a new file into.
 *
 * The file ownership handler (Files) answers it and binds the answer in its own registrar.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
interface FileAccountInterface {

	/**
	 * Gets or creates the OpenRegister user for file operations.
	 *
	 * @throws \Exception If OpenRegister user cannot be created.
	 *
	 * @return IUser The OpenRegister user.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function getUser(): IUser;

	/**
	 * The user logged in to this request, or null when nobody is.
	 *
	 * @return IUser|null The currently active user or null if no user is logged in.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function getCurrentUser(): ?IUser;

	/**
	 * Transfer file ownership to the OpenRegister user and share it with the current user.
	 *
	 * @param File $file The file to potentially transfer ownership for.
	 *
	 * @return void
	 *
	 * @throws \Exception If ownership transfer fails.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function transferFileOwnershipIfNeeded(File $file): void;
}//end interface
