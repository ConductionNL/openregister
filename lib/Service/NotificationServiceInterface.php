<?php

/**
 * NotificationServiceInterface: what callers outside the Service component ask of NotificationService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\Configuration;

/**
 * Tells administrators that a configuration has an update available.
 */
interface NotificationServiceInterface {

	/**
	 * Send notification about configuration update availability
	 *
	 * Notifies all users in configured notification groups about available
	 * configuration updates. Always includes admin group regardless of configuration.
	 * Deduplicates users across multiple groups to avoid duplicate notifications.
	 *
	 * @param Configuration $configuration The configuration entity with available update
	 *
	 * @return int Number of notifications successfully sent (0 or positive integer)
	 *
	 * @psalm-return int<0, max>
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function notifyConfigurationUpdate(Configuration $configuration): int;

	/**
	 * Mark configuration update notification as processed.
	 *
	 * Removes notifications for a specific configuration after update is applied.
	 *
	 * @param Configuration $configuration The configuration that was updated
	 *
	 * @return void
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function markConfigurationUpdated(Configuration $configuration): void;
}//end interface
