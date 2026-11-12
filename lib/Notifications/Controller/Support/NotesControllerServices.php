<?php

/**
 * OpenRegister notes controller services
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
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

namespace OCA\OpenRegister\Notifications\Controller\Support;

use OCA\OpenRegister\Kernel\Contracts\NoteServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Notifications\Service\TimelineVisibilityService;
use OCA\OpenRegister\Notifications\Service\Timeline\TimelineWriteService;

/**
 * The services of the notes API.
 *
 * @spec openspec/specs/object-interactions/spec.md
 */
final class NotesControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param NoteServiceInterface               $noteService   Note service for comment operations
	 * @param ObjectOperationsInterface             $objectService Object service for object validation
	 * @param TimelineVisibilityService $visibility    Visibility guard, filter and audit
	 * @param TimelineWriteService      $timeline      Projects a note into the entry record, so it is searchable
	 *
	 * @spec openspec/specs/object-interactions/spec.md
	 */
	public function __construct(
		public readonly NoteServiceInterface $noteService,
		public readonly ObjectOperationsInterface $objectService,
		public readonly TimelineVisibilityService $visibility,
		public readonly TimelineWriteService $timeline,
	) {
	}//end __construct()
}//end class
