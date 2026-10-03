<?php

/**
 * The grants one object holds because an ancestor was shared.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/rbac-inherits-to-children/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\File\FolderManagementHandler;
use OCP\Files\Folder;
use OCP\Share\IManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads the inherited half of an object's access review.
 *
 * WHY IT IS NOT IN `ObjectSharingService`. That class WRITES: it grants,
 * mints links, invites addresses and revokes. This one only reads, it never
 * revokes, and the distinction is load-bearing — an inherited entry carries
 * the ANCESTOR's share id, so a revoke driven from it would remove the grant
 * from the ancestor, which is a much larger act than the row suggests. Two
 * classes make that impossible to do by reaching for the nearest method.
 *
 * It also takes the ancestor walk (`HierarchyDescender`) out of the write
 * surface's constructor, which nothing on the write paths ever used.
 *
 * @spec openspec/changes/rbac-inherits-to-children/specs/rbac-scopes/spec.md
 */
class InheritedGrantLister {

	/**
	 * Share types {@see ObjectSharingService::listGrants()} reports.
	 *
	 * A SUPERSET of GRANTABLE_TYPES, and deliberately a separate constant.
	 * Links and email invitations are created by their own endpoints
	 * ({@see createLink()}, {@see inviteByEmail()}) rather than by
	 * {@see grant()}, so they must NOT become grantable — `type=link` posted to
	 * the grant endpoint would bypass the link surface's own rules. But they
	 * must be LISTED, because a capability you cannot see is a capability you
	 * cannot revoke.
	 *
	 * While this listed principals only, links and email invitations were
	 * write-only: `createLink()` minted a working public link that never
	 * appeared in the panel, so the revoke control for it did not exist and the
	 * only way to withdraw it was raw SQL or core's Files UI. Caught by driving
	 * the link control through the browser (task 10.3) — the create and the
	 * anonymous redeem both passed, and the revoke had nothing to click.
	 *
	 * @var array<string, int>
	 */
	public const LISTABLE_TYPES = [
		'user' => IShare::TYPE_USER,
		'group' => IShare::TYPE_GROUP,
		'remote' => IShare::TYPE_REMOTE,
		'remote_group' => IShare::TYPE_REMOTE_GROUP,
		'link' => IShare::TYPE_LINK,
		'email' => IShare::TYPE_EMAIL,
	];

	/**
	 * Constructor.
	 *
	 * @param HierarchyDescender      $hierarchy    Resolves an object's ancestors.
	 * @param MagicMapper             $mapper       Reads an ancestor object.
	 * @param FolderManagementHandler $folders      Resolves an object's NC folder.
	 * @param IManager                $shareManager Core share manager.
	 * @param LoggerInterface         $logger       Where an unreadable ancestor is noted.
	 */
	public function __construct(
		private readonly HierarchyDescender $hierarchy,
		private readonly MagicMapper $mapper,
		private readonly FolderManagementHandler $folders,
		private readonly IManager $shareManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The grants this object holds through an ancestor (REQ-RIC-004).
	 *
	 * "Why can this person see it" is the question an administrator actually
	 * asks, and before this it had no answer for an inherited grant: the share
	 * is written on the ANCESTOR's folder, so a listing of this object's own
	 * folder is empty and the access is unexplained and unremovable from the
	 * object in front of them.
	 *
	 * Each entry names the ancestor it came from and is marked `inherited`, so
	 * the two kinds are distinguishable rather than merged. They are
	 * deliberately NOT deduplicated against the direct grants above: a
	 * principal who holds both a direct grant and an inherited one holds two
	 * facts, and collapsing them would hide whichever one an administrator is
	 * about to revoke.
	 *
	 * 🔴 IT NEVER REVOKES. An inherited entry carries the ancestor's share id,
	 * and revoking it removes the grant from the ANCESTOR, which is a much
	 * larger act than the row suggests. The entry says where to go; the
	 * revocation happens there.
	 *
	 * @param ObjectEntity $object The object being audited.
	 *
	 * @return array<int, array<string, mixed>> The inherited grants, ancestor named.
	 *
	 * @spec openspec/changes/rbac-inherits-to-children/specs/rbac-scopes/spec.md
	 */
	public function inheritedGrantsFor(ObjectEntity $object): array {
		$ancestors = [];
		try {
			$ancestors = $this->hierarchy->ancestorsOf(
				registerId: (int)$object->getRegister(),
				schemaId: (int)$object->getSchema(),
				objectUuid: (string)$object->getUuid()
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[InheritedGrantLister] Could not resolve the ancestors of an object; its inherited grants are not listed',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'object' => (string)$object->getUuid(),
					'exception' => $e->getMessage(),
				]
			);
			return [];
		}

		$inherited = [];
		foreach ($ancestors as $ancestorUuid) {
			try {
				$ancestor = $this->mapper->find(identifier: $ancestorUuid, _rbac: false, _multitenancy: false);
			} catch (Throwable $e) {
				// An ancestor this caller cannot resolve contributes nothing.
				// Saying so would be worse than silence here: the listing is
				// already gated on owner-or-admin, and an entry naming an
				// object nobody can open explains nothing.
				continue;
			}

			$folder = $this->resolveFolder(object: $ancestor);
			if ($folder === null) {
				continue;
			}

			foreach (self::LISTABLE_TYPES as $label => $shareType) {
				try {
					$shares = $this->shareManager->getSharesBy(
						(string)$ancestor->getOwner(),
						$shareType,
						$folder,
						false,
						-1
					);
				} catch (Throwable $e) {
					continue;
				}

				foreach ($shares as $share) {
					$inherited[] = [
						'id' => $share->getFullId(),
						'type' => $label,
						'sharedWith' => $share->getSharedWith(),
						'permissions' => $share->getPermissions(),
						'expiration' => $share->getExpirationDate()?->format('c'),
						'inherited' => true,
						'inheritedFrom' => $ancestorUuid,
					];
				}
			}
		}//end foreach

		return $inherited;
	}//end inheritedGrantsFor()

	/**
	 * Resolve the object's NC folder, creating it if it has none.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return Folder|null The folder, or null when it cannot be resolved.
	 */
	private function resolveFolder(ObjectEntity $object): ?Folder {
		try {
			$folder = $this->folders->getObjectFolder($object);
			if (($folder instanceof Folder) === true) {
				return $folder;
			}

			return null;
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[InheritedGrantLister] Could not resolve an object folder',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
			return null;
		}
	}//end resolveFolder()

}//end class
