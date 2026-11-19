<?php
/**
 * FileSettingsInterface: the stored file-management settings, as Core reads them.
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
 * The read side of the file-management settings.
 *
 * Core asks it for the archive bounds and the review policy default;
 * Configuration keeps the settings and binds its handler in its own bindings.
 *
 * @spec openspec/changes/archive-members-are-searchable/specs
 */
interface FileSettingsInterface {

	/**
	 * The file-management settings, stored values over the defaults.
	 *
	 * @return array The file-management settings.
	 *
	 * @throws \RuntimeException When the settings cannot be read.
	 *
	 * @spec openspec/changes/archive-members-are-searchable/specs
	 */
	public function getFileSettingsOnly(): array;
}//end interface
