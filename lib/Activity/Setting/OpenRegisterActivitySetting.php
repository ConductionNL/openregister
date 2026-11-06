<?php

/**
 * OpenRegister OpenRegisterActivitySetting
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Activity
 * @package  OCA\OpenRegister\Activity\Setting
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Activity\Setting;

use OCP\Activity\ActivitySettings;
use OCP\IL10N;

/**
 * An activity setting in the Open Register group: changeable in stream and
 * mail, on in the stream and off in mail by default.
 */
abstract class OpenRegisterActivitySetting extends ActivitySettings {
	/**
	 * Constructor.
	 *
	 * @param IL10N $l The localization service.
	 *
	 * @return void
	 */
	public function __construct(
		protected IL10N $l,
	) {
	}//end __construct()

	/**
	 * Get the group identifier.
	 *
	 * @return string The group identifier.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function getGroupIdentifier(): string {
		return 'openregister';
	}//end getGroupIdentifier()

	/**
	 * Get the group name.
	 *
	 * @return string The translated group name.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function getGroupName(): string {
		return $this->l->t('Open Register');
	}//end getGroupName()

	/**
	 * Whether the user can change the stream setting.
	 *
	 * @return bool True.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function canChangeStream(): bool {
		return true;
	}//end canChangeStream()

	/**
	 * Whether the stream is enabled by default.
	 *
	 * @return bool True.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function isDefaultEnabledStream(): bool {
		return true;
	}//end isDefaultEnabledStream()

	/**
	 * Whether the user can change the mail setting.
	 *
	 * @return bool True.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function canChangeMail(): bool {
		return true;
	}//end canChangeMail()

	/**
	 * Whether mail is enabled by default.
	 *
	 * @return bool False.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function isDefaultEnabledMail(): bool {
		return false;
	}//end isDefaultEnabledMail()
}//end class
