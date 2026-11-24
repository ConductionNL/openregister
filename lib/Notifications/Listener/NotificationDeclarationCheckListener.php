<?php

/**
 * NotificationDeclarationCheckListener: adds the check of `x-openregister-notifications` to the schema save.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Notifications\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Listener;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper\NotificationAnnotationCheck;
use OCA\OpenRegister\Db\SchemaMapper\PositionedDeclarationCheck;
use OCA\OpenRegister\Db\SchemaMapper\SchemaDeclarationChecksEvent;
use OCA\OpenRegister\Db\SchemaMapper\SchemaDeclarationOrder;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Contributes the notification annotation check in its slot of SchemaDeclarationOrder.
 *
 * The check refuses a url action that cannot be linked to, unless the save only
 * warns about one (a configuration import, or a save that keeps the stored block);
 * every other fault is a warning.
 *
 * @template-implements IEventListener<SchemaDeclarationChecksEvent>
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final class NotificationDeclarationCheckListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Receives the warnings of the check.
	 */
	public function __construct(private readonly LoggerInterface $logger) {
	}//end __construct()

	/**
	 * Contribute the check.
	 *
	 * @param Event $event The collection event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof SchemaDeclarationChecksEvent) === false) {
			return;
		}

		$logger = $this->logger;
		$event->add(
			check: new PositionedDeclarationCheck(
				position: SchemaDeclarationOrder::NOTIFICATIONS,
				check: static function (Schema $schema) use ($logger, $event): void {
					$check = new NotificationAnnotationCheck(logger: $logger);
					if ($event->warnsOnUnlinkableNotificationActions() === true) {
						$check->warn(schema: $schema);
						return;
					}

					$check->check(schema: $schema);
				}
			)
		);
	}//end handle()
}//end class
