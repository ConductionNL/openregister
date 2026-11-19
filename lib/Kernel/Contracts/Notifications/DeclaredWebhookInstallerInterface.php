<?php

/**
 * DeclaredWebhookInstallerInterface: installs the webhooks a schema's notifications declare.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Notifications
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

namespace OCA\OpenRegister\Kernel\Contracts\Notifications;

use OCP\EventDispatcher\Event;

/**
 * The installer behind the notifications annotation listener.
 *
 * Notifications listens for the schema-saved events and hands each one over;
 * Configuration installs the persistent webhooks the schema's
 * `x-openregister-notifications` declare and binds its installer in its own wiring.
 *
 * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid1/tasks.md#task-5
 */
interface DeclaredWebhookInstallerInterface {

	/**
	 * Install the webhooks the schema on a schema-saved event declares.
	 *
	 * @param Event $event The event carrying the saved schema.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid1/tasks.md#task-5
	 */
	public function handle(Event $event): void;
}//end interface
