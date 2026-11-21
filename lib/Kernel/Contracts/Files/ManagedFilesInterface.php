<?php

/**
 * ManagedFilesInterface: openregister's managed document store, as other areas see it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Files
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Files;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\Files\Node;

/**
 * The root of the managed document store, and the object that owns a managed file.
 *
 * Files answers (ManagedFiles); higher areas that react to file changes ask.
 *
 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
 */
interface ManagedFilesInterface {

	/**
	 * The store's root folder path in the openregister account's home.
	 *
	 * @return string The root, without leading or trailing slashes.
	 *
	 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
	 */
	public function root(): string;

	/**
	 * The object whose folder a managed file sits in.
	 *
	 * @param Node $file The file.
	 *
	 * @return ObjectEntity|null The object, or null when no object owns the file.
	 *
	 * @throws \Throwable When the file's folder or the object lookup fails.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function ownerOf(Node $file): ?ObjectEntity;
}//end interface
