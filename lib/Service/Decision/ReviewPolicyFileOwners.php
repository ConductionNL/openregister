<?php

/**
 * OpenRegister review policy file owners
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Decision
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/entity-relation-grondslagen/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Decision;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\File\ManagedFileOwnerLookup;
use OCP\Files\File;
use OCP\Files\IRootFolder;

/**
 * Finds a file by id and the object that owns it.
 *
 * @spec openspec/specs/entity-relation-grondslagen/spec.md
 */
final class ReviewPolicyFileOwners {
	/**
	 * The account whose home holds every object's files (the document store's account).
	 */
	private const ACCOUNT = 'openregister';

	/**
	 * Bundle the file lookups.
	 *
	 * @param IRootFolder            $rootFolder The root folder, for finding a file by id.
	 * @param ManagedFileOwnerLookup $owners     The object that owns a managed file.
	 *
	 * @spec openspec/specs/entity-relation-grondslagen/spec.md
	 */
	public function __construct(
		public readonly IRootFolder $rootFolder,
		public readonly ManagedFileOwnerLookup $owners,
	) {
	}//end __construct()

	/**
	 * The object that owns a file in OpenRegister's own tree, found by the file's id.
	 *
	 * An object's files live in the openregister account's home, which the acting user has no mount on, so the file
	 * is looked up there rather than through the acting user's own mounts.
	 *
	 * @param int $fileId The file.
	 *
	 * @return ObjectEntity|null The object, or null when the file is not there or belongs to no object.
	 *
	 * @throws \Throwable When the file's folder or the object lookup fails.
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/entity-relation-grondslagen/spec.md
	 */
	public function objectOwningFile(int $fileId): ?ObjectEntity {
		$node = $this->rootFolder->getUserFolder(self::ACCOUNT)->getFirstNodeById($fileId);
		if ($node instanceof File === false) {
			return null;
		}

		return $this->owners->find(file: $node);
	}//end objectOwningFile()
}//end class
