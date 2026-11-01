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
	 * The shares on one object's folder, keyed by their full share id.
	 *
	 * Core's `getSharesBy()` answers per sharer. Without `$complete` it asks for
	 * the shares each of `$sharers` created, and skips a type core cannot read,
	 * which is how the `/shares` panel has always listed. With `$complete` it
	 * also asks as the folder's owner with re-shares included, which returns
	 * every share on the folder whoever created it, and a share type that
	 * cannot be read throws instead of shrinking the answer.
	 *
	 * The two readings also find the folder differently. Without `$complete`
	 * the folder is created when the object has none, as `/shares` always did.
	 * With `$complete` nothing is created: an object that never had a folder,
	 * or whose folder is gone, has no shares, and a folder or folder owner
	 * that cannot be looked up throws.
	 *
	 * @param ObjectEntity $object   The object.
	 * @param string[]     $sharers  The uids whose shares to read.
	 * @param bool         $complete Every share on the folder, failing loudly.
	 *
	 * @return array<string, array{type: string, share: IShare}> The shares with their type label.
	 *
	 * @throws RuntimeException When `$complete` is set and the folder, its owner or a share type cannot be read.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The two readings differ only in reach and in failure,
	 *                                              and both callers name the flag.
	 *
	 * @spec openspec/changes/an-object-lists-its-grants-with-their-origin/specs/rbac-scopes/spec.md
	 */
	public function sharesOn(ObjectEntity $object, array $sharers, bool $complete = false): array {
		$folder = match ($complete) {
			true => $this->existingFolder(object: $object),
			false => $this->resolveFolder(object: $object),
		};

		if ($folder === null) {
			return [];
		}

		if ($complete === true) {
			$sharers[] = $this->folderOwner(folder: $folder);
		}

		$sharers = array_values(array_unique(array_filter($sharers, static fn ($uid) => is_string($uid) === true && $uid !== '')));

		$found = [];
		foreach ($sharers as $sharer) {
			foreach (self::LISTABLE_TYPES as $label => $shareType) {
				foreach ($this->sharesBy(sharer: $sharer, shareType: $shareType, folder: $folder, complete: $complete) as $share) {
					$found[$share->getFullId()] = ['type' => $label, 'share' => $share];
				}
			}
		}

		return $found;
	}//end sharesOn()

	/**
	 * The shares one sharer created on the folder, for one type.
	 *
	 * @param string          $sharer    The uid.
	 * @param IShare::TYPE_*  $shareType The core share type.
	 * @param Folder          $folder    The object's folder.
	 * @param bool            $complete  Include re-shares and fail loudly.
	 *
	 * @return IShare[] The shares.
	 *
	 * @throws RuntimeException When `$complete` is set and core cannot read them.
	 */
	private function sharesBy(string $sharer, int $shareType, Folder $folder, bool $complete): array {
		try {
			return $this->shareManager->getSharesBy($sharer, $shareType, $folder, $complete, -1);
		} catch (Throwable $e) {
			if ($complete === true) {
				throw new RuntimeException('The shares on an object folder could not be read', 0, $e);
			}

			return [];
		}
	}//end sharesBy()

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

		if ($this->rootFolder === null) {
			throw new RuntimeException('No read-only folder lookup is wired');
		}

		try {
			$nodes = $this->rootFolder->getById((int)$folderId);
		} catch (Throwable $e) {
			throw new RuntimeException('The folder of an object could not be looked up', 0, $e);
		}

		foreach ($nodes as $node) {
			if (($node instanceof Folder) === true) {
				return $node;
			}
		}

		return null;
	}//end existingFolder()

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
