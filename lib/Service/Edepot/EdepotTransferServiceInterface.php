<?php

/**
 * EdepotTransferServiceInterface: the part of EdepotTransferService that the e-Depot settings controller uses.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Edepot
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-support-transfer-list-management
 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-log-all-transfer-actions-in-the-audit-trail
 * @spec openspec/specs/edepot-transfer/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Edepot;

/**
 * The methods of EdepotTransferService that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface EdepotTransferServiceInterface {

	/**
	 * Get available SIP profile names.
	 *
	 * @return array<string, string> Map of profile ID to display name.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-support-configurable-e-depot-endpoint-settings
	 */
	public function getAvailableProfiles(): array;

	/**
	 * Get the transport configuration from app settings.
	 *
	 * `''` for a credential means NOT CONFIGURED: both transports refuse to
	 * send in that state, and the missing value is logged. The empty values
	 * are still returned: the settings screen has to render the unconfigured
	 * form. See {@see TransferSettings}.
	 *
	 * @return array<string,mixed> The transport configuration.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-support-configurable-e-depot-endpoint-settings
	 */
	public function getTransportConfig(): array;

	/**
	 * Validate a SIP profile name.
	 *
	 * @param string $profileName The profile name to validate.
	 *
	 * @return bool True if valid.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-support-configurable-e-depot-endpoint-settings
	 */
	public function isValidProfile(string $profileName): bool;
}//end interface
