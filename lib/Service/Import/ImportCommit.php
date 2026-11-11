<?php

/**
 * ImportCommit: which file a preview commit claims to commit, and who commits it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Import
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Import;

use OCP\IUser;

/**
 * A commit of a preview's decisions: the sha256 of the file being committed
 * (required in practice; a hash that differs from the preview's is refused)
 * and the actor.
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
 */
final class ImportCommit {

	/**
	 * Constructor.
	 *
	 * @param string|null $sourceHash  The sha256 of the file being committed.
	 * @param IUser|null  $currentUser The actor.
	 */
	public function __construct(
		public readonly ?string $sourceHash,
		public readonly ?IUser $currentUser = null,
	) {
	}//end __construct()
}//end class
