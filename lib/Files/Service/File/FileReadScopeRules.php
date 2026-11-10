<?php

/**
 * OpenRegister file read scope rules
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
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\File;

/**
 * The rules a file-search read scope applies beyond the caller's tree: the object read rule for an object's file and the file restriction guard.
 *
 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md#requirement-reading-an-objects-files-follows-the-objects-read-rule-req-ofoa-002
 */
final class FileReadScopeRules {
	/**
	 * Bundle the collaborators.
	 *
	 * @param ObjectFileAccess|null     $objectFileAccess The object read rule for an object's file.
	 * @param FileRestrictionGuard|null $restrictions     Refuses a restricted file the caller is not admitted to.
	 *
	 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md#requirement-reading-an-objects-files-follows-the-objects-read-rule-req-ofoa-002
	 */
	public function __construct(
		public readonly ?ObjectFileAccess $objectFileAccess = null,
		public readonly ?FileRestrictionGuard $restrictions = null,
	) {
	}//end __construct()
}//end class
