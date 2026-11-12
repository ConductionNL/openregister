<?php

/**
 * PortalTaskServiceInterface: a subject's portal tasks, listed, shown and completed.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Portal
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Portal;

use OCA\OpenRegister\Db\Task;

/**
 * What the portal task controller needs of PortalTaskService.
 * Bound to PortalTaskService in ContractBindings, so it resolves to the same shared instance.
 */
interface PortalTaskServiceInterface {

	/**
	 * The outcome a portal completion records when the party names none.
	 *
	 * @var string
	 */
	public const DEFAULT_OUTCOME = 'submitted';

	/**
	 * One subject's open portal tasks with case context, and the total the
	 * same predicates count.
	 *
	 * @param PortalSubject $subject The acting subject.
	 * @param int           $limit   Page size (clamped to 1..500).
	 * @param int           $offset  Page offset.
	 *
	 * @return array{results: array<int, array<string, mixed>>, total: int, limit: int, offset: int} The page.
	 */
	public function listForSubject(PortalSubject $subject, int $limit = 25, int $offset = 0): array;

	/**
	 * One portal task, if it is this subject's.
	 *
	 * @param PortalSubject $subject The acting subject.
	 * @param string        $uuid    The task uuid.
	 *
	 * @return Task The task.
	 */
	public function show(PortalSubject $subject, string $uuid): Task;

	/**
	 * The row shape of one task for the portal, with delivery state and case context.
	 *
	 * @param Task $task The task.
	 *
	 * @return array<string, mixed> The row.
	 */
	public function row(Task $task): array;

	/**
	 * Complete a portal task as the acting subject, storing its uploads on the case object first.
	 *
	 * @param PortalSubject                    $subject The acting subject.
	 * @param string                           $uuid    The task uuid.
	 * @param PortalCompletion $completion What the party submitted: answers, comment, uploads and outcome.
	 * @param string                           $viaApp  The app completing the task in-process, or empty.
	 *
	 * @return Task The completed task.
	 */
	public function complete(
		PortalSubject $subject,
		string $uuid,
		PortalCompletion $completion=new PortalCompletion(),
		string $viaApp = '',
	): Task;
}//end interface
