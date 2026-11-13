<?php

/**
 * The Nextcloud shares on one object's folder, read without a guard.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/an-object-lists-its-grants-with-their-origin/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\File\FolderManagementHandler;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Share\IManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Reads the shares on an object's folder; every caller guards before it calls.
 *
 * 🔴 THIS CLASS CHECKS NOTHING. `ObjectSharingService::listGrants()` runs its
 * grant-manage guard first and `ObjectGrantsController` runs the read-grants
 * gate first. A new caller that skips its own guard hands every share on a
 * record to whoever asks.
 *
 * @spec openspec/changes/an-object-lists-its-grants-with-their-origin/specs/rbac-scopes/spec.md
 */
class ObjectShareReader {

	/**
	 * The share types that can sit on an object folder, by the label the API uses.
	 *
	 * @var array<string, IShare::TYPE_*>
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
	 * @param FolderManagementHandler $folders      Resolves an object's NC folder, creating it when missing.
	 * @param IManager                $shareManager Core share manager.
	 * @param LoggerInterface         $logger       Where an unresolvable folder is noted.
	 * @param IRootFolder|null        $rootFolder   Looks a folder up by id without creating anything.
	 */
	public function __construct(
		private readonly FolderManagementHandler $folders,
		private readonly IManager $shareManager,
		private readonly LoggerInterface $logger,
		private readonly ?IRootFolder $rootFolder = null,
	) {
	}//end __construct()

	/**
	 * The shares on one object's folder, keyed by their full share id, as the `/shares` panel lists them.
	 *
	 * Core's `getSharesBy()` answers per sharer: this asks for the shares each
	 * of `$sharers` created, and skips a type core cannot read, which is how
	 * the `/shares` panel has always listed. The folder is created when the
	 * object has none, as `/shares` always did. {@see completeSharesOn()} is
	 * the reading that misses nothing.
	 *
	 * @param ObjectEntity $object  The object.
	 * @param string[]     $sharers The uids whose shares to read.
	 *
	 * @return array<string, array{type: string, share: IShare}> The shares with their type label.
	 *
	 * @spec openspec/changes/an-object-lists-its-grants-with-their-origin/specs/rbac-scopes/spec.md
	 */
	public function sharesOn(ObjectEntity $object, array $sharers): array {
		$folder = $this->resolveFolder(object: $object);
		if ($folder === null) {
			return [];
		}

		return $this->sharesIn(scan: new FolderShareScan(folder: $folder, complete: false), sharers: $sharers);
	}//end sharesOn()

	/**
	 * Every share on one object's folder, keyed by its full share id, failing loudly.
	 *
	 * Besides the shares each of `$sharers` created, this also asks as the
	 * folder's owner with re-shares included, which returns every share on the
	 * folder whoever created it, and a share type that cannot be read throws
	 * instead of shrinking the answer. Nothing is created: an object that never
	 * had a folder, or whose folder is gone, has no shares, and a folder or
	 * folder owner that cannot be looked up throws.
	 *
	 * @param ObjectEntity $object  The object.
	 * @param string[]     $sharers The uids whose shares to read.
	 *
	 * @return array<string, array{type: string, share: IShare}> The shares with their type label.
	 *
	 * @throws RuntimeException When the folder, its owner or a share type cannot be read.
	 *
	 * @spec openspec/changes/an-object-lists-its-grants-with-their-origin/specs/rbac-scopes/spec.md
	 */
	public function completeSharesOn(ObjectEntity $object, array $sharers): array {
		$folder = $this->existingFolder(object: $object);
		if ($folder === null) {
			return [];
		}

		return $this->sharesIn(scan: new FolderShareScan(folder: $folder, complete: true), sharers: $sharers);
	}//end completeSharesOn()

	/**
	 * The shares the sharers (and, for a complete reading, the folder owner) created on the folder.
	 *
	 * @param FolderShareScan $scan    The folder and the reading.
	 * @param string[]        $sharers The uids whose shares to read.
	 *
	 * @return array<string, array{type: string, share: IShare}> The shares with their type label.
	 *
	 * @throws RuntimeException When the reading is complete and the owner or a share type cannot be read.
	 */
	private function sharesIn(FolderShareScan $scan, array $sharers): array {
		$owner = match ($scan->complete) {
			true => $this->folderOwner(folder: $scan->folder),
			false => $this->folderOwnerOrNone(folder: $scan->folder),
		};

		$sharers = array_values(array_unique(array_filter($sharers, static fn ($uid) => is_string($uid) === true && $uid !== '' && $uid !== $owner)));

		$found = [];
		foreach ($sharers as $sharer) {
			foreach ($this->sharesBy(scan: $scan, sharer: $sharer, reshares: $scan->complete) as $fullId => $row) {
				$found[$fullId] = $row;
			}
		}

		// Asked as the folder's owner with re-shares, core answers every share on
		// the folder, so a grant stays listed whoever made it, including one whose
		// sharer has since lost the object.
		if ($owner !== '') {
			foreach ($this->sharesBy(scan: $scan, sharer: $owner, reshares: true) as $fullId => $row) {
				$found[$fullId] = $row;
			}
		}

		return $found;
	}//end sharesIn()

	/**
	 * The shares one sharer created on the folder, for every listable type.
	 *
	 * @param FolderShareScan $scan     The folder and the reading.
	 * @param string          $sharer   The uid.
	 * @param bool            $reshares Whether shares made by others on the sharer's node count too.
	 *
	 * @return array<string, array{type: string, share: IShare}> The shares with their type label.
	 *
	 * @throws RuntimeException When the reading is complete and core cannot read them.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Core's own re-shares flag, passed through.
	 */
	private function sharesBy(FolderShareScan $scan, string $sharer, bool $reshares): array {
		$found = [];
		foreach (self::LISTABLE_TYPES as $label => $shareType) {
			try {
				$shares = $this->shareManager->getSharesBy($sharer, $shareType, $scan->folder, $reshares, -1);
			} catch (Throwable $e) {
				$shares = $this->unreadable(scan: $scan, error: $e);
			}

			foreach ($shares as $share) {
				// A sharer's view of the folder grants nothing, so it is no grant to list.
				if ((new ShareGrantAttributes())->isSharerAccess(share: $share) === true) {
					continue;
				}

				$found[$share->getFullId()] = ['type' => $label, 'share' => $share];
			}
		}

		return $found;
	}//end sharesBy()

	/**
	 * What a failed read yields: nothing for a lenient reading, an exception for a complete one.
	 *
	 * @param FolderShareScan $scan  The reading.
	 * @param Throwable       $error What core threw.
	 *
	 * @return IShare[] No shares.
	 *
	 * @throws RuntimeException When the reading is complete.
	 */
	private function unreadable(FolderShareScan $scan, Throwable $error): array {
		if ($scan->complete === true) {
			throw new RuntimeException('The shares on an object folder could not be read', 0, $error);
		}

		return [];
	}//end unreadable()

	/**
	 * The uid that owns the folder, or an empty string when it cannot be read.
	 *
	 * @param Folder $folder The folder.
	 *
	 * @return string The owner uid, or ''.
	 */
	private function folderOwnerOrNone(Folder $folder): string {
		try {
			return $this->folderOwner(folder: $folder);
		} catch (RuntimeException $e) {
			return '';
		}
	}//end folderOwnerOrNone()

	/**
	 * The uid that owns the folder.
	 *
	 * @param Folder $folder The folder.
	 *
	 * @return string The owner uid.
	 *
	 * @throws RuntimeException When the owner cannot be read.
	 */
	private function folderOwner(Folder $folder): string {
		try {
			$owner = $folder->getOwner()?->getUID();
		} catch (Throwable $e) {
			throw new RuntimeException('The owner of an object folder could not be read', 0, $e);
		}

		if ($owner === null || $owner === '') {
			throw new RuntimeException('An object folder has no owner to read its shares as');
		}

		return $owner;
	}//end folderOwner()

	/**
	 * The object's folder as it stands, never created or recreated.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return Folder|null The folder, or null when the object has none or its folder is gone.
	 *
	 * @throws RuntimeException When the lookup itself fails.
	 */
	private function existingFolder(ObjectEntity $object): ?Folder {
		$folderId = $object->getFolder();
		if (is_numeric($folderId) === false) {
			return null;
		}

		// The app's own read-only lookup first: the OpenRegister user's folder,
		// then the root. It reports a failed lookup as null, so a miss is
		// confirmed against the root, where a failure throws.
		$node = $this->folders->getNodeById(nodeId: (int)$folderId);
		if (($node instanceof Folder) === true) {
			return $node;
		}

		return $this->folderFromRoot(folderId: (int)$folderId);
	}//end existingFolder()

	/**
	 * Confirm a folder against the root: the first folder among its nodes for the id.
	 *
	 * @param int $folderId The folder's node id.
	 *
	 * @return Folder|null The folder, or null when the root has none.
	 *
	 * @throws RuntimeException When no root is wired or the root lookup fails.
	 */
	private function folderFromRoot(int $folderId): ?Folder {
		if ($this->rootFolder === null) {
			throw new RuntimeException('No read-only folder lookup is wired');
		}

		try {
			$nodes = $this->rootFolder->getById($folderId);
		} catch (Throwable $e) {
			throw new RuntimeException('The folder of an object could not be looked up', 0, $e);
		}

		return self::firstFolder(nodes: $nodes);
	}//end folderFromRoot()

	/**
	 * The first folder among nodes.
	 *
	 * @param array<mixed> $nodes The nodes.
	 *
	 * @return Folder|null
	 */
	private static function firstFolder(array $nodes): ?Folder {
		foreach ($nodes as $node) {
			if (($node instanceof Folder) === true) {
				return $node;
			}
		}

		return null;
	}//end firstFolder()

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
				message: '[ObjectShareReader] Could not resolve an object folder',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
			return null;
		}
	}//end resolveFolder()
}//end class
