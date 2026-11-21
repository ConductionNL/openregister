<?php

/**
 * ManagedFiles: openregister's managed document store, as other areas ask about it.
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
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\File;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Files\ManagedFilesInterface;
use OCA\OpenRegister\Service\File\DocumentStore;
use OCA\OpenRegister\Service\File\ManagedFileOwnerLookup;
use OCP\Files\Node;

/**
 * Answers the managed-files port from the document store and the owner lookup, delegating unchanged.
 *
 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
 */
final class ManagedFiles implements ManagedFilesInterface {

	/**
	 * Constructor.
	 *
	 * @param DocumentStore          $store  The document store, for its root.
	 * @param ManagedFileOwnerLookup $owners Finds the object whose folder a file sits in.
	 */
	public function __construct(
		private readonly DocumentStore $store,
		private readonly ManagedFileOwnerLookup $owners,
	) {
	}//end __construct()

	/**
	 * The store's root folder path, as DocumentStore::root() reads it.
	 *
	 * @return string The root, without leading or trailing slashes.
	 *
	 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
	 */
	public function root(): string {
		return $this->store->root();
	}//end root()

	/**
	 * The object that owns a managed file, as ManagedFileOwnerLookup::find() answers it.
	 *
	 * @param Node $file The file.
	 *
	 * @return ObjectEntity|null The object, or null when no object owns the file.
	 *
	 * @throws \Throwable When the file's folder or the object lookup fails.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function ownerOf(Node $file): ?ObjectEntity {
		return $this->owners->find(file: $file);
	}//end ownerOf()
}//end class
