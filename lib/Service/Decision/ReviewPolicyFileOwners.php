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
 * @spec openspec/changes/reviewer-owns-their-decisions/specs/entity-relation-grondslagen/spec.md#requirement-a-reviewers-decision-is-changed-only-by-that-reviewer-or-a-supervisor-req-rod-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Decision;

use OCA\OpenRegister\Service\File\ManagedFileOwnerLookup;
use OCP\Files\IRootFolder;

/**
 * Finds a file by id and the object that owns it.
 *
 * @spec openspec/changes/reviewer-owns-their-decisions/specs/entity-relation-grondslagen/spec.md#requirement-a-reviewers-decision-is-changed-only-by-that-reviewer-or-a-supervisor-req-rod-001
 */
final class ReviewPolicyFileOwners {
	/**
	 * Bundle the file lookups.
	 *
	 * @param IRootFolder            $rootFolder The root folder, for finding a file by id.
	 * @param ManagedFileOwnerLookup $owners     The object that owns a managed file.
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/entity-relation-grondslagen/spec.md#requirement-a-reviewers-decision-is-changed-only-by-that-reviewer-or-a-supervisor-req-rod-001
	 */
	public function __construct(
		public readonly IRootFolder $rootFolder,
		public readonly ManagedFileOwnerLookup $owners,
	) {
	}//end __construct()
}//end class
