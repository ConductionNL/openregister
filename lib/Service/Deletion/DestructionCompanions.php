<?php

/**
 * OpenRegister destruction companions
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Deletion
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

namespace OCA\OpenRegister\Service\Deletion;

use OCA\OpenRegister\Kernel\Contracts\NoteServiceInterface;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectRelationCleanupService;
use OCA\OpenRegister\Service\TaskService;

/**
 * The optional services that carry out the cascading part of a declared destruction scope.
 *
 * @spec openspec/specs/deletion-audit-trail/spec.md
 */
final class DestructionCompanions {
	/**
	 * Bundle the collaborators.
	 *
	 * @param ?NoteServiceInterface         $noteService     Notes on the object.
	 * @param ?FileService                  $fileService     The object's bound folder.
	 * @param ?TaskService                  $taskService     CalDAV tasks linked to the object.
	 * @param ?ObjectRelationCleanupService $relationCleanup The remaining timeline links.
	 */
	public function __construct(
		public readonly ?NoteServiceInterface $noteService = null,
		public readonly ?FileService $fileService = null,
		public readonly ?TaskService $taskService = null,
		public readonly ?ObjectRelationCleanupService $relationCleanup = null,
	) {
	}//end __construct()
}//end class
