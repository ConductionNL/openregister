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
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;
use RuntimeException;
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
 * @spec openspec/specs/rbac-scopes/spec.md
 */
class InheritedGrantLister {

	/**
	 * Constructor.
	 *
	 * @param HierarchyDescender $hierarchy Resolves an object's ancestors.
	 * @param MagicMapper        $mapper    Reads an ancestor object.
	 * @param ObjectShareReader  $shares    Reads the shares on an ancestor's folder.
	 * @param LoggerInterface    $logger    Where an unreadable ancestor is noted.
	 */
	public function __construct(
		private readonly HierarchyDescender $hierarchy,
		private readonly MagicMapper $mapper,
		private readonly ObjectShareReader $shares,
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
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function inheritedGrantsFor(ObjectEntity $object): array {
		$inherited = [];
		foreach ($this->inheritedSharesFor(object: $object) as $found) {
			$share = $found['share'];
			$inherited[] = [
				'id' => $share->getFullId(),
				'type' => $found['type'],
				'sharedWith' => $share->getSharedWith(),
				'permissions' => $share->getPermissions(),
				'expiration' => $share->getExpirationDate()?->format('c'),
				'inherited' => true,
				'inheritedFrom' => (string)$found['ancestor']->getUuid(),
			];
		}

		return $inherited;
	}//end inheritedGrantsFor()

	/**
	 * The shares on this object's ancestors, each with the ancestor it sits on.
	 *
	 * Without `$complete` this is the reading {@see inheritedGrantsFor()} has
	 * always made: the shares each ancestor's owner created, and an ancestor
	 * walk that cannot be made reads as no ancestors. With `$complete` every
	 * share on an ancestor's folder is read, whoever created it, and a walk or
	 * a share read that fails throws, so a caller can tell "nothing inherited"
	 * from "could not tell".
	 *
	 * An ancestor that cannot be found contributes nothing in either reading:
	 * an entry naming an object nobody can open explains nothing.
	 *
	 * @param ObjectEntity $object   The object being audited.
	 * @param bool         $complete Every share, failing loudly.
	 *
	 * @return array<int, array{ancestor: ObjectEntity, type: string, share: IShare}> The shares.
	 *
	 * @throws RuntimeException When `$complete` is set and a source cannot be read.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The same two readings as ObjectShareReader::sharesOn().
	 *
	 * @spec openspec/changes/an-object-lists-its-grants-with-their-origin/specs/rbac-scopes/spec.md
	 */
	public function inheritedSharesFor(ObjectEntity $object, bool $complete = false): array {
		$found = [];
		foreach ($this->ancestorsOf(object: $object, complete: $complete) as $ancestorUuid) {
			try {
				$ancestor = $this->mapper->find(identifier: $ancestorUuid, _rbac: false, _multitenancy: false);
			} catch (Throwable $e) {
				continue;
			}

			$shares = $this->shares->sharesOn(
				object: $ancestor,
				sharers: [(string)$ancestor->getOwner()],
				complete: $complete
			);
			foreach ($shares as $share) {
				$found[] = ['ancestor' => $ancestor, 'type' => $share['type'], 'share' => $share['share']];
			}
		}

		return $found;
	}//end inheritedSharesFor()

	/**
	 * The uuids of this object's ancestors.
	 *
	 * @param ObjectEntity $object   The object.
	 * @param bool         $complete Throw rather than answer none when the walk fails.
	 *
	 * @return string[] The ancestor uuids.
	 *
	 * @throws RuntimeException When `$complete` is set and the walk fails.
	 */
	private function ancestorsOf(ObjectEntity $object, bool $complete): array {
		try {
			return $this->hierarchy->ancestorsOf(
				registerId: (int)$object->getRegister(),
				schemaId: (int)$object->getSchema(),
				objectUuid: (string)$object->getUuid()
			);
		} catch (Throwable $e) {
			if ($complete === true) {
				throw new RuntimeException('The ancestors of an object could not be resolved', 0, $e);
			}

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
		}//end try
	}//end ancestorsOf()
}//end class
