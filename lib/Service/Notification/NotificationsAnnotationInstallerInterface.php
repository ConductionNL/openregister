<?php

/**
 * NotificationsAnnotationInstallerInterface: what callers outside the component use of NotificationsAnnotationInstaller.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Notification;

use OCP\EventDispatcher\Event;

/**
 * Synchronises the persistent webhooks a schema declares, on the schema-saved events.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface NotificationsAnnotationInstallerInterface {

	/**
	 * Listener entry point: dispatches schema-saved events to the installer.
	 *
	 * @param Event $event The event carrying the saved schema.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid1/tasks.md#task-5
	 */
	public function handle(Event $event): void;
}//end interface
