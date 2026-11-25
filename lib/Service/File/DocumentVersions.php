<?php

/**
 * Earlier versions of a managed file, read through the versions app.
 *
 * The move carries a file's earlier versions to its copy oldest first. The
 * versions app is optional, and a storage may keep no versions at all, so
 * everything is reached through the container and answers "none" when the app
 * or its manager is absent.
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use OCP\App\IAppManager;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Lists, reads and detects support for earlier versions.
 *
 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
 */
class DocumentVersions {

	/**
	 * The versions app's manager, by name: the app is optional.
	 */
	public const MANAGER = 'OCA\Files_Versions\Versions\IVersionManager';

	/**
	 * Constructor.
	 *
	 * @param IAppManager        $appManager  Says whether the versions app is enabled.
	 * @param IUserManager       $userManager Resolves the openregister account.
	 * @param ContainerInterface $container   Reaches the optional version manager.
	 *
	 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly IUserManager $userManager,
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * Whether a folder's storage keeps earlier versions.
	 *
	 * @param Folder $folder The target root.
	 *
	 * @return bool False when the versions app is off, absent, or has no backend for the storage.
	 *
	 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
	 */
	public function keepsVersions(Folder $folder): bool {
		$manager = $this->manager();
		if ($manager === null || method_exists($manager, 'getBackendForStorage') === false) {
			return false;
		}

		try {
			$manager->getBackendForStorage($folder->getStorage());
		} catch (\Throwable $e) {
			return false;
		}

		return true;
	}//end keepsVersions()

	/**
	 * A file's earlier versions, oldest first.
	 *
	 * @param File $file The file.
	 *
	 * @return list<object> The versions app's version objects (`getTimestamp()`).
	 *
	 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
	 */
	public function versionsOf(File $file): array {
		$manager = $this->manager();
		$account = $this->userManager->get(DocumentStore::ACCOUNT);
		if ($manager === null || $account === null) {
			return [];
		}

		$versions = [];
		foreach ($manager->getVersionsForFile($account, $file) as $version) {
			if (is_object($version) === true) {
				$versions[] = $version;
			}
		}

		usort($versions, static fn (object $a, object $b): int => ((int)$a->getTimestamp() <=> (int)$b->getTimestamp()));
		return $versions;
	}//end versionsOf()

	/**
	 * Open an earlier version's bytes.
	 *
	 * @param object $version A version from versionsOf().
	 *
	 * @return resource
	 *
	 * @throws RuntimeException When the version cannot be read.
	 *
	 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
	 */
	public function read(object $version) {
		$manager = $this->manager();
		$stream = false;
		if ($manager !== null) {
			$stream = $manager->read($version);
		}

		if (is_resource($stream) === false) {
			throw new RuntimeException('An earlier version cannot be read.');
		}

		return $stream;
	}//end read()

	/**
	 * The version manager, or null when the versions app is off or absent.
	 *
	 * @return object|null
	 */
	private function manager(): ?object {
		if ($this->appManager->isInstalled('files_versions') === false || interface_exists(self::MANAGER) === false) {
			return null;
		}

		try {
			$manager = $this->container->get(self::MANAGER);
		} catch (\Throwable $e) {
			return null;
		}

		if (is_object($manager) === false) {
			return null;
		}

		return $manager;
	}//end manager()
}//end class
