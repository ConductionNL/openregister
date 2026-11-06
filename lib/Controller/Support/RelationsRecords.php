<?php

/**
 * OpenRegister relations domain
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

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Kernel\Contracts\ObjectCalendarTasksInterface;
use OCA\OpenRegister\Notifications\Service\EmailServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\NoteServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;

/**
 * The domain services of the relations API.
 *
 * @spec openspec/changes/retrofit-2026-05-24-data-integrity-relations/tasks.md#task-1
 */
final class RelationsRecords {
	/**
	 * Bundle the services.
	 *
	 * @param ObjectOperationsInterface                $objectService Object service
	 * @param NoteServiceInterface                  $noteService   Note service
	 * @param ObjectCalendarTasksInterface $taskService   Task service
	 * @param EmailServiceInterface                 $emailService  Email service
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-data-integrity-relations/tasks.md#task-1
	 */
	public function __construct(
		public readonly ObjectOperationsInterface $objectService,
		public readonly NoteServiceInterface $noteService,
		public readonly ObjectCalendarTasksInterface $taskService,
		public readonly EmailServiceInterface $emailService,
	) {
	}//end __construct()
}//end class
