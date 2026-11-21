<?php

/**
 * CallerFileRightsInterface: whether the caller of a file endpoint may open a file.
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
 * The one "may the caller open this file" check of the per-file endpoints.
 *
 * Files answers (CallerFileAccess); higher areas' file endpoints ask.
 *
 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md
 */
interface CallerFileRightsInterface {

	/**
	 * Whether the current session user may open the given Nextcloud file.
	 *
	 * @param int $fileId Nextcloud file ID.
	 *
	 * @return bool True when the caller may open it.
	 *
	 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md
	 */
	public function mayOpen(int $fileId): bool;
}//end interface
