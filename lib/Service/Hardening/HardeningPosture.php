<?php

/**
 * The instance's hardening posture, as the hardening services read and write it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Hardening
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Hardening;

/**
 * The declared policy and floors, the store administered values are written to,
 * the audit writer for hardening decisions, and the platform's own security
 * settings. Read by the report, the settings writer and the elevation check.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Hardening
 */
final class HardeningPosture {

	/**
	 * Constructor.
	 *
	 * @param HardeningPolicy        $policy   The administered controls and the declared floors.
	 * @param HardeningSettingsStore $store    The stored settings, their audit trail and the log.
	 * @param HardeningAuditWriter   $audit    Records elevation grants and refusals.
	 * @param PlatformSecurityReader $platform Nextcloud's own password, session and brute-force posture.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly HardeningPolicy $policy,
		public readonly HardeningSettingsStore $store,
		public readonly HardeningAuditWriter $audit,
		public readonly PlatformSecurityReader $platform,
	) {

	}//end __construct()
}//end class
