<?php

/**
 * FilesScan: a capped depth-first walk over a user's folder tree.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

use OCP\Files\File;
use OCP\Files\Folder;

/**
 * The state of one folder walk: folders still to list, files found, and
 * nodes scanned. Every listed child counts towards the cap; the cap is
 * checked before each folder is listed.
 *
 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
 */
final class FilesScan {

	/**
	 * Folders still to list (last in, first out).
	 *
	 * @var list<Folder>
	 */
	private array $pending;

	/**
	 * Files found so far, in visiting order.
	 *
	 * @var list<File>
	 */
	private array $files = [];

	/**
	 * Child nodes scanned so far.
	 *
	 * @var int
	 */
	private int $scanned = 0;

	/**
	 * Constructor.
	 *
	 * @param Folder $root The folder to start from.
	 * @param int $cap The maximum number of nodes to scan.
	 *
	 * @return void
	 */
	public function __construct(
		Folder $root,
		private readonly int $cap,
	) {
		$this->pending = [$root];
	}//end __construct()

	/**
	 * The next folder to list, or null when none is left or the cap is reached.
	 *
	 * @return Folder|null The folder.
	 *
	 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
	 */
	public function next(): ?Folder {
		if ($this->pending === [] || $this->scanned >= $this->cap) {
			return null;
		}

		return array_pop($this->pending);
	}//end next()

	/**
	 * Count a folder's children, keeping its files and queueing its sub-folders.
	 *
	 * @param mixed $children The folder listing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
	 */
	public function visit(mixed $children): void {
		foreach ($children as $child) {
			$this->scanned++;
			if ($child instanceof File) {
				$this->files[] = $child;
			} elseif ($child instanceof Folder) {
				$this->pending[] = $child;
			}
		}
	}//end visit()

	/**
	 * The files found.
	 *
	 * @return list<File> The files.
	 *
	 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
	 */
	public function files(): array {
		return $this->files;
	}//end files()
}//end class
