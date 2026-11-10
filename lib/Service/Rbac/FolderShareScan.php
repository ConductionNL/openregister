<?php

/**
 * One reading of the shares on an object's folder.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/an-object-lists-its-grants-with-their-origin/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCP\Files\Folder;

/**
 * The folder being read and whether the reading is complete (re-shares included, failing loudly).
 *
 * @spec openspec/changes/an-object-lists-its-grants-with-their-origin/specs/rbac-scopes/spec.md
 */
final class FolderShareScan {

	/**
	 * Constructor.
	 *
	 * @param Folder $folder   The object's folder.
	 * @param bool   $complete Include re-shares and fail loudly.
	 */
	public function __construct(
		public readonly Folder $folder,
		public readonly bool $complete,
	) {
	}//end __construct()
}//end class
