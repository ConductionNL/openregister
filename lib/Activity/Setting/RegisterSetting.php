<?php

/**
 * OpenRegister RegisterSetting.
 *
 * Activity setting for register CRUD notifications.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Activity
 * @package  OCA\OpenRegister\Activity\Setting
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Activity\Setting;

/**
 * Activity setting for register events.
 */
class RegisterSetting extends OpenRegisterActivitySetting {
	/**
	 * Get the identifier for this setting.
	 *
	 * @return string The setting identifier.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-activity-provider/tasks.md#task-1
	 */
	public function getIdentifier(): string {
		return 'openregister_registers';
	}//end getIdentifier()

	/**
	 * Get the name for this setting.
	 *
	 * @return string The setting name.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-activity-provider/tasks.md#task-1
	 */
	public function getName(): string {
		return $this->l->t('Register changes');
	}//end getName()

	/**
	 * Get the priority for this setting.
	 *
	 * @return int The priority.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-activity-provider/tasks.md#task-1
	 */
	public function getPriority(): int {
		return 52;
	}//end getPriority()
}//end class
