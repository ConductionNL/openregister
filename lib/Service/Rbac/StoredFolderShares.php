<?php

/**
 * Which folder shares are on the folder stored for the object they name.
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
 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/object-level-sharing/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Db\MagicMapper\MagicCrossTableReaderInterface;
use OCP\Share\IShare;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Keeps the folder shares whose node is the folder stored for their object.
 *
 * A folder's name only says which object a share is about. A folder
 * elsewhere that carries the same name is not the object's folder and
 * grants nothing.
 *
 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/object-level-sharing/spec.md
 */
final class StoredFolderShares {

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface    $logger    Logger.
	 * @param ContainerInterface $container App container the object reader is resolved from on demand.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * The candidates whose share node is the folder stored for their object.
	 *
	 * @param list<array{0: string, 1: IShare}> $candidates Object uuid and share pairs.
	 *
	 * @return list<array{0: string, 1: IShare}> The admitted pairs; none when the stored folders cannot be read.
	 *
	 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/object-level-sharing/spec.md
	 */
	public function admitted(array $candidates): array {
		if ($candidates === []) {
			return [];
		}

		$folders = $this->storedFolders(uuids: array_column($candidates, 0));

		return array_values(
			array_filter(
				$candidates,
				static fn (array $candidate): bool => self::isOnFolder(share: $candidate[1], folder: ($folders[$candidate[0]] ?? null))
			)
		);
	}//end admitted()

	/**
	 * The stored folder file id of each object, or nothing when they cannot be read.
	 *
	 * @param string[] $uuids The object uuids.
	 *
	 * @return array<string, int> Uuid => folder file id.
	 */
	private function storedFolders(array $uuids): array {
		try {
			$objects = $this->container->get(MagicCrossTableReaderInterface::class);
			if (($objects instanceof MagicCrossTableReaderInterface) === true) {
				return $objects->findFolderIdsByUuids(uuids: $uuids);
			}
		} catch (Throwable $e) {
			// Logged below; an unreadable folder table grants nothing.
		}

		$this->logger->error(
			message: '[ObjectGrantResolver] Could not read the stored object folders; treating the caller as holding no grants',
			context: ['file' => __FILE__, 'line' => __LINE__]
		);

		return [];
	}//end storedFolders()

	/**
	 * Whether a share's node is the given folder.
	 *
	 * @param IShare       $share  The share.
	 * @param integer|null $folder The object's stored folder file id, or null when it has none.
	 *
	 * @return bool
	 */
	private static function isOnFolder(IShare $share, ?int $folder): bool {
		if ($folder === null) {
			return false;
		}

		try {
			return $share->getNodeId() === $folder;
		} catch (Throwable $e) {
			return false;
		}
	}//end isOnFolder()
}//end class
