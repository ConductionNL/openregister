<?php

/**
 * OpenRegister SchemaSetting.
 *
 * Activity setting for schema CRUD notifications.
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
 * Activity setting for schema events.
 */
class SchemaSetting extends OpenRegisterActivitySetting {
	/**
	 * Get the identifier for this setting.
	 *
	 * @return string The setting identifier.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function getIdentifier(): string {
		return 'openregister_schemas';
	}//end getIdentifier()

	/**
	 * Get the name for this setting.
	 *
	 * @return string The setting name.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function getName(): string {
		return $this->l->t('Schema changes');
	}//end getName()

	/**
	 * Get the priority for this setting.
	 *
	 * @return int The priority.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function getPriority(): int {
		return 53;
	}//end getPriority()
}//end class
