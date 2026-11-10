<?php

/**
 * OpenRegister settings audit trail collaborators
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
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Settings;

use OCA\OpenRegister\Service\Audit\SecuritySettingAnnouncer;
use OCA\OpenRegister\Service\Audit\SecuritySettingRegistry;
use OCA\OpenRegister\Service\Rbac\SettingsChangeAuditor;

/**
 * Where a settings change goes: the audit rows, the registry that knows which
 * keys are secret, and the announcer that tells the administrators.
 *
 * @spec openspec/specs/audit-trail-immutable/spec.md
 */
final class SettingsAuditTrail {
	/**
	 * Bundle the audit trail collaborators.
	 *
	 * @param SettingsChangeAuditor         $auditor   Writes the audit rows.
	 * @param SecuritySettingRegistry       $registry  Knows which keys are secret.
	 * @param SecuritySettingAnnouncer|null $announcer Tells the administrators.
	 *
	 * @spec openspec/specs/audit-trail-immutable/spec.md
	 */
	public function __construct(
		public readonly SettingsChangeAuditor $auditor,
		public readonly SecuritySettingRegistry $registry,
		public readonly ?SecuritySettingAnnouncer $announcer = null,
	) {
	}//end __construct()
}//end class
