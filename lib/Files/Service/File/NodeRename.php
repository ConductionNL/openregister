<?php

/**
 * A rename of one node inside an object folder.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Files\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-folder-in-files-browser/specs/file-actions/spec.md#requirement-changing-an-objects-folder-and-its-subfolders-follows-the-objects-update-rule
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\File;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\Files\Node;

/**
 * The object, the node inside its folder and the node's new name.
 *
 * @spec openspec/changes/object-folder-in-files-browser/specs/file-actions/spec.md#requirement-changing-an-objects-folder-and-its-subfolders-follows-the-objects-update-rule
 */
final class NodeRename {
	/**
	 * Constructor.
	 *
	 * @param ObjectEntity $object The object, already known to be changeable.
	 * @param Node         $node   The node, known to be inside the object folder.
	 * @param string       $name   The new name, already validated as one segment.
	 *
	 * @spec openspec/changes/object-folder-in-files-browser/specs/file-actions/spec.md#requirement-changing-an-objects-folder-and-its-subfolders-follows-the-objects-update-rule
	 */
	public function __construct(
		public readonly ObjectEntity $object,
		public readonly Node $node,
		public readonly string $name,
	) {
	}//end __construct()
}//end class
