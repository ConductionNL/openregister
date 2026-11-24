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

use OCA\OpenRegister\Kernel\Contracts\Core\ObjectFolderLookupInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\ObjectTimelineLinksInterface;
use OCA\OpenRegister\Kernel\Contracts\NoteServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectCalendarTasksInterface;
use OCA\OpenRegister\Service\ObjectFilesInterface;

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
	 * @param ?ObjectFilesInterface         $fileService     The object's files.
	 * @param ?ObjectCalendarTasksInterface $taskService     CalDAV tasks linked to the object.
	 * @param ?ObjectTimelineLinksInterface $relationCleanup The remaining timeline links.
	 * @param ?ObjectFolderLookupInterface  $objectFolders   The object's bound folder; when absent, the file
	 *                                                       service answers if it can look folders up.
	 */
	public function __construct(
		public readonly ?NoteServiceInterface $noteService = null,
		public readonly ?ObjectFilesInterface $fileService = null,
		public readonly ?ObjectCalendarTasksInterface $taskService = null,
		public readonly ?ObjectTimelineLinksInterface $relationCleanup = null,
		private readonly ?ObjectFolderLookupInterface $objectFolders = null,
	) {
	}//end __construct()

	/**
	 * The service that finds an object's bound folder, or null when none is wired.
	 *
	 * @return ObjectFolderLookupInterface|null
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	public function objectFolders(): ?ObjectFolderLookupInterface {
		if ($this->objectFolders !== null) {
			return $this->objectFolders;
		}

		if ($this->fileService instanceof ObjectFolderLookupInterface) {
			return $this->fileService;
		}

		return null;
	}//end objectFolders()
}//end class
