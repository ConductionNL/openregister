<?php

/**
 * ObjectFolderReshareGuard: a share another user makes of an OpenRegister-owned folder never carries re-share.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/object-level-sharing/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Service\File\DocumentStore;
use OCP\Constants;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Share\Events\BeforeShareCreatedEvent;

/**
 * Clears the re-share bit from a share of a folder the OpenRegister account owns, made by somebody else.
 *
 * A sharer view (`SharerFolderAccess`) carries the re-share bit, because
 * Nextcloud 34 checks a share against the node as the sharer reaches it. That
 * bit lets the view's holder share the object's folder, which is the point,
 * but a share they make from the Files app would hand the bit on, and an
 * object grant IS a folder share: its recipient could then re-share the object
 * onward, which a recipient may never do. OpenRegister's own grant and link
 * writes already drop the bit; this clears it on every other path, before core
 * stores the share. The OpenRegister account's own shares (the views
 * themselves) are left alone.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/object-level-sharing/spec.md
 */
final class ObjectFolderReshareGuard implements IEventListener {

	/**
	 * Drop the re-share bit from a share of an OpenRegister folder by another user.
	 *
	 * @param Event $event The share about to be created.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/object-level-sharing/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof BeforeShareCreatedEvent) === false) {
			return;
		}

		$share = $event->getShare();
		if ($share->getShareOwner() !== DocumentStore::ACCOUNT || $share->getSharedBy() === DocumentStore::ACCOUNT) {
			return;
		}

		$permissions = (int)$share->getPermissions();
		if (($permissions & Constants::PERMISSION_SHARE) !== 0) {
			$share->setPermissions($permissions & ~Constants::PERMISSION_SHARE);
		}
	}//end handle()
}//end class
