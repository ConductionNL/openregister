<?php

/**
 * Keeps only the recipients who may read the object a notification is about.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/notifications-new-notes-and-referrers/specs/notificatie-engine/spec.md#requirement-a-rule-can-fire-when-a-note-is-added
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A read check per recipient, failing closed.
 *
 * Every uid is asked `read` on the object, as that user, with the object's
 * owner. A uid whose check throws, or a filter without a permission handler,
 * reaches nobody: a notification that cannot prove its recipient may read
 * the object is not sent.
 *
 * @spec openspec/changes/notifications-new-notes-and-referrers/specs/notificatie-engine/spec.md#requirement-a-rule-can-fire-when-a-note-is-added
 */
class ReadableRecipients {

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface                 $logger      Records a check that could not be made.
	 * @param PermissionHandlerInterface|null $permissions The read check; without it nobody passes.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly ?PermissionHandlerInterface $permissions = null,
	) {
	}//end __construct()

	/**
	 * The uids, in their order, that may read the object.
	 *
	 * @param array<int, string> $uids   The resolved recipients.
	 * @param ObjectEntity       $object The object the notification is about.
	 * @param Schema             $schema The object's schema.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/notifications-new-notes-and-referrers/specs/notificatie-engine/spec.md#scenario-a-watcher-who-lost-access-is-not-told
	 */
	public function filter(array $uids, ObjectEntity $object, Schema $schema): array {
		if ($uids === []) {
			return [];
		}

		if ($this->permissions === null) {
			$this->logger->warning('[ReadableRecipients] no permission handler; the notification reaches nobody');
			return [];
		}

		$kept = [];
		foreach ($uids as $uid) {
			if ($this->mayRead(uid: $uid, object: $object, schema: $schema) === true) {
				$kept[] = $uid;
			}
		}

		return $kept;
	}//end filter()

	/**
	 * Whether one user may read the object; false when the check fails.
	 *
	 * @param string       $uid    The user.
	 * @param ObjectEntity $object The object.
	 * @param Schema       $schema The object's schema.
	 *
	 * @return bool
	 */
	private function mayRead(string $uid, ObjectEntity $object, Schema $schema): bool {
		try {
			return $this->permissions?->hasPermission(
				schema: $schema,
				action: 'read',
				userId: $uid,
				objectOwner: $object->getOwner(),
				_rbac: true,
				object: $object
			) === true;
		} catch (Throwable $e) {
			$this->logger->warning(sprintf('[ReadableRecipients] read check failed: %s', $e->getMessage()));
			return false;
		}
	}//end mayRead()
}//end class
