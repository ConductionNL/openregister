<?php

/**
 * OpenRegister ObjectSetting.
 *
 * Activity setting for object CRUD notifications.
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
 * Activity setting for object events.
 */
class ObjectSetting extends OpenRegisterActivitySetting {
	/**
	 * Get the identifier for this setting.
	 *
	 * @return string The setting identifier.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function getIdentifier(): string {
		return 'openregister_objects';
	}//end getIdentifier()

	/**
	 * Get the name for this setting.
	 *
	 * @return string The setting name.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function getName(): string {
		return $this->l->t('Object changes');
	}//end getName()

	/**
	 * Get the priority for this setting.
	 *
	 * @return int The priority.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function getPriority(): int {
		return 51;
	}//end getPriority()
}//end class
