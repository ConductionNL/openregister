<?php

/**
 * ImportPreviewServiceInterface: what callers outside the component use of ImportPreviewService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Import
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Import;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ImportPreview;
use OCP\IUser;

/**
 * Preview an import, then commit it under a conflict policy.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface ImportPreviewServiceInterface {

	/**
	 * Create the preview record. Nothing is decided and nothing is written
	 * yet: this only fixes what the preview is of.
	 *
	 * @param array<string, mixed> $params Preview parameters: register, schema,
	 *                                     filePath, sourceName, format, policy,
	 *                                     matchKey, packSlug.
	 * @param IUser|null $currentUser The actor.
	 *
	 * @return ImportPreview The created preview, in state pending.
	 *
	 * @throws InvalidArgumentException When a parameter is missing or unknown.
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
	 */
	public function createPreview(array $params, ?IUser $currentUser = null): ImportPreview;

	/**
	 * Decide every row of a pending preview, and write nothing.
	 *
	 * @param ImportPreview $preview The pending preview.
	 *
	 * @return ImportPreview The preview, in state previewed.
	 *
	 * @throws InvalidArgumentException When the source can no longer be read.
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
	 */
	public function runPreview(ImportPreview $preview): ImportPreview;

	/**
	 * Create and run a preview in one call.
	 *
	 * @param array<string, mixed> $params Preview parameters.
	 * @param IUser|null $currentUser The actor.
	 *
	 * @return ImportPreview The preview, in state previewed.
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
	 */
	public function preview(array $params, ?IUser $currentUser = null): ImportPreview;

	/**
	 * Apply the decisions a preview made.
	 *
	 * The source hash is required, not optional: a commit that cannot say
	 * which file it is committing cannot claim the decisions still describe
	 * it. A hash that differs from the preview's is refused with nothing
	 * written (D-1).
	 *
	 * @param ImportPreview $preview The previewed preview.
	 * @param ImportCommit  $commit  The sha256 of the file being committed, and the actor.
	 *
	 * @return ImportPreview The preview, in state committed.
	 *
	 * @throws ImportPreviewRefusedException When the state or the hash refuses the commit.
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
	 */
	public function commit(ImportPreview $preview, ImportCommit $commit): ImportPreview;
}//end interface
