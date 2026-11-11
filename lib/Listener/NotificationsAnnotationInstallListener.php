<?php

/**
 * Hands the schema-saved events to the notifications annotation installer.
 *
 * The installer (lib/Service/Notification) synchronises the Webhook rows a
 * schema's `x-openregister-notifications` block declares as persistent. This
 * listener is the registration point the Listener component owns, so the
 * registrar names the installer only through its interface.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid1/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Event\SchemaCreatedEvent;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCA\OpenRegister\Service\Notification\NotificationsAnnotationInstallerInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Forwards each schema-saved event to the installer, unchanged.
 *
 * @template-implements IEventListener<SchemaCreatedEvent|SchemaUpdatedEvent>
 */
final class NotificationsAnnotationInstallListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param NotificationsAnnotationInstallerInterface $installer Synchronises the declared webhooks.
	 */
	public function __construct(
		private readonly NotificationsAnnotationInstallerInterface $installer,
	) {
	}//end __construct()

	/**
	 * Forward the event to the installer.
	 *
	 * @param Event $event The event carrying the saved schema.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid1/tasks.md#task-5
	 */
	public function handle(Event $event): void {
		$this->installer->handle(event: $event);
	}//end handle()
}//end class
