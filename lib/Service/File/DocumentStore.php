<?php

/**
 * The store that holds OpenRegister's document bytes.
 *
 * Every managed file sits in one tree in the openregister account's home. The
 * folder that tree starts at is the document store root: app config
 * `document_store_root`, `Open Registers` when unset. An organisation that wants
 * its documents on a store of their own mounts that store with Nextcloud's own
 * tools (the External storage app, for the openregister account) and points the
 * root at it. This service owns the root and names the store behind it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
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

namespace OCA\OpenRegister\Service\File;

use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IAppConfig;
use RuntimeException;

/**
 * Owns the document store root and describes the storage it is on.
 *
 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
 */
class DocumentStore {

	/**
	 * The root when none is configured.
	 */
	public const DEFAULT_ROOT = 'Open Registers';

	/**
	 * The app config key naming the root.
	 */
	public const ROOT_KEY = 'document_store_root';

	/**
	 * The account whose home holds the managed tree.
	 */
	public const ACCOUNT = 'openregister';

	/**
	 * The storage id prefix of object storage (S3, Swift, Azure) as primary storage.
	 */
	private const OBJECT_STORE_PREFIX = 'object::';

	/**
	 * Free space a storage reports when it does not know it (FileInfo::SPACE_* are negative).
	 */
	private const SPACE_KNOWN_FROM = 0;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig  $appConfig  Holds `document_store_root`.
	 * @param IRootFolder $rootFolder Reaches the openregister account's home.
	 *
	 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IRootFolder $rootFolder,
	) {
	}//end __construct()

	/**
	 * The root folder's path in the openregister account's home.
	 *
	 * Unset, empty or a value that would leave the home (`..`) is the default.
	 *
	 * @return string The root, without leading or trailing slashes.
	 *
	 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
	 */
	public function root(): string {
		$root = trim($this->appConfig->getValueString('openregister', self::ROOT_KEY, self::DEFAULT_ROOT), " \t/");
		if ($root === '' || in_array('..', explode('/', $root), true) === true) {
			return self::DEFAULT_ROOT;
		}

		return $root;
	}//end root()

	/**
	 * The root folder, created in the openregister account's home on first use.
	 *
	 * @return Folder
	 *
	 * @throws RuntimeException When the root exists and is not a folder.
	 *
	 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
	 */
	public function rootFolder(): Folder {
		$home = $this->home();
		$root = $this->root();
		if ($home->nodeExists($root) === false) {
			return $home->newFolder($root);
		}

		$node = $home->get($root);
		if ($node instanceof Folder === false) {
			throw new RuntimeException(sprintf('The document store root "%s" is not a folder.', $root));
		}

		return $node;
	}//end rootFolder()

	/**
	 * Where the documents live: root, storage, backend, mount point, whether separate, free space.
	 *
	 * `backend` is `local`, `object-storage` or `external`; `backendName` names
	 * the storage class behind it. `separate` is true when the root's storage
	 * is not the storage of the openregister account's home, which is the
	 * instance's primary storage.
	 *
	 * @return array{root: string, storageId: string, backend: string, backendName: string, mountPoint: string, separate: bool, freeBytes: int|null}
	 *
	 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
	 */
	public function describe(): array {
		$folder = $this->rootFolder();
		$storage = $folder->getStorage();
		$mount = $folder->getMountPoint();

		return [
			'root' => $this->root(),
			'storageId' => (string)$storage->getId(),
			'backend' => self::backend(mountType: (string)$mount->getMountType(), objectStore: str_starts_with((string)$storage->getId(), self::OBJECT_STORE_PREFIX)),
			'backendName' => self::shortName(class: get_class($storage)),
			'mountPoint' => (string)$mount->getMountPoint(),
			'separate' => ((string)$storage->getId() !== (string)$this->home()->getStorage()->getId()),
			'freeBytes' => self::freeBytes(node: $folder),
		];
	}//end describe()

	/**
	 * The openregister account's home.
	 *
	 * @return Folder
	 */
	private function home(): Folder {
		return $this->rootFolder->getUserFolder(self::ACCOUNT);
	}//end home()

	/**
	 * The backend kind of a mount.
	 *
	 * @param string $mountType   The mount type (`external` for an External storage mount).
	 * @param bool   $objectStore Whether the storage is object storage.
	 *
	 * @return string `external`, `object-storage` or `local`.
	 */
	private static function backend(string $mountType, bool $objectStore): string {
		if ($mountType === 'external') {
			return 'external';
		}

		if ($objectStore === true) {
			return 'object-storage';
		}

		return 'local';
	}//end backend()

	/**
	 * A class name without its namespace, lower-cased: `local`, `amazons3`, `smb`.
	 *
	 * @param string $class The class name.
	 *
	 * @return string
	 */
	private static function shortName(string $class): string {
		$parts = explode('\\', $class);
		return strtolower((string)end($parts));
	}//end shortName()

	/**
	 * The free space a node's storage reports, null when it does not know.
	 *
	 * @param Node $node The root folder.
	 *
	 * @return int|null
	 */
	private static function freeBytes(Node $node): ?int {
		if ($node instanceof Folder === false) {
			return null;
		}

		try {
			$free = $node->getFreeSpace();
		} catch (\Throwable $e) {
			return null;
		}

		if (is_numeric($free) === false || $free < self::SPACE_KNOWN_FROM) {
			return null;
		}

		return (int)$free;
	}//end freeBytes()
}//end class
