<?php

/**
 * End a removed user's sharer views, with the grants and links they made.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/object-level-sharing/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Service\Rbac\SharerFolderAccess;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\BeforeUserDeletedEvent;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Revokes the object shares a user made, before the user is removed.
 *
 * Core deletes the user's sharer views itself, but not the grants and links
 * the user made on the openregister account's folders.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/object-level-sharing/spec.md
 */
class SharerViewsEndWithUserListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param SharerFolderAccess $sharerAccess Ends the views and the grants made through them.
	 * @param LoggerInterface    $logger       Where a failure is reported.
	 */
	public function __construct(
		private readonly SharerFolderAccess $sharerAccess,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * End the user's views and revoke what they shared through them.
	 *
	 * @param Event $event The user about to be removed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/object-level-sharing/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof BeforeUserDeletedEvent) === false) {
			return;
		}

		try {
			$this->sharerAccess->endAllViewsOf(uid: $event->getUser()->getUID());
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[SharerViewsEndWithUserListener] Could not end the object shares of a removed user',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
		}
	}//end handle()
}//end class
