<?php
/**
 * ArchivalSettingsInterface: the stored archival settings the archival work reads.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Compliance
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Compliance;

/**
 * Reads the archival settings, with their defaults filled in.
 *
 * The archival services (Compliance) ask; the settings handler (Configuration)
 * answers and is bound to this contract.
 */
interface ArchivalSettingsInterface {

	/**
	 * The archival settings, every key present, stored values over the defaults.
	 *
	 * @return array<string, mixed> The archival settings.
	 *
	 * @spec openspec/specs/retention-management/spec.md#requirement-retention-settings-must-be-configurable-via-api
	 */
	public function getArchivalSettingsOnly(): array;
}//end interface
