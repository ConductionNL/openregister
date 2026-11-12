<?php

/**
 * Reads the stored archival settings, with their defaults filled in.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Settings;

/**
 * The read side of the archival settings, for callers that only read them.
 *
 * The container binds it to {@see ObjectRetentionHandler} in ContractBindings.
 */
interface ArchivalSettingsReaderInterface {

	/**
	 * The archival settings, every key present, stored values over the defaults.
	 *
	 * @return array<string, mixed> The archival settings.
	 *
	 * @spec openspec/specs/retention-management/spec.md#requirement-retention-settings-must-be-configurable-via-api
	 */
	public function getArchivalSettingsOnly(): array;
}//end interface
