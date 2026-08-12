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

use OCP\Activity\ActivitySettings;
use OCP\IL10N;

/**
 * Activity setting for schema events.
 */
class SchemaSetting extends ActivitySettings {
	/**
	 * Constructor.
	 *
	 * @param IL10N $l The localization service.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function __construct(
		private IL10N $l,
	) {
	}//end __construct()

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
	 * Get the group identifier for this setting.
	 *
	 * @return string The group identifier.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function getGroupIdentifier(): string {
		return 'openregister';
	}//end getGroupIdentifier()

	/**
	 * Get the group name for this setting.
	 *
	 * @return string The group name.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function getGroupName(): string {
		return $this->l->t('Open Register');
	}//end getGroupName()

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

	/**
	 * Whether the user can change the stream setting.
	 *
	 * @return bool True if changeable.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function canChangeStream(): bool {
		return true;
	}//end canChangeStream()

	/**
	 * Whether the stream is enabled by default.
	 *
	 * @return bool True if enabled by default.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function isDefaultEnabledStream(): bool {
		return true;
	}//end isDefaultEnabledStream()

	/**
	 * Whether the user can change the mail setting.
	 *
	 * @return bool True if changeable.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function canChangeMail(): bool {
		return true;
	}//end canChangeMail()

	/**
	 * Whether mail is enabled by default.
	 *
	 * @return bool True if enabled by default.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function isDefaultEnabledMail(): bool {
		return false;
	}//end isDefaultEnabledMail()
}//end class
