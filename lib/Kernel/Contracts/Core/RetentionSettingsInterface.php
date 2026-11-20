<?php

/**
 * RetentionSettingsInterface: the retention settings, as the log and deletion sweeps read them.
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
 * Reads the stored retention settings, with their defaults filled in.
 *
 * The sweeps that act on retention (log clean-up, the deletion window) ask;
 * the settings (Configuration) answer and bind their handler to this contract
 * in their own binding map.
 *
 * @spec openspec/specs/retention-management/spec.md#requirement-retention-settings-must-be-configurable-via-api
 */
interface RetentionSettingsInterface {

	/**
	 * The retention settings, every key present, stored values over the defaults.
	 *
	 * @return array<string, mixed> The retention settings.
	 *
	 * @spec openspec/specs/retention-management/spec.md#requirement-retention-settings-must-be-configurable-via-api
	 */
	public function getRetentionSettingsOnly(): array;
}//end interface
