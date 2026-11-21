<?php

/**
 * OpenRegister portal task completion command.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Portal
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-an-upload-completion-lands-as-a-file-attachment-on-the-case-object
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Portal;

/**
 * One portal task completion: who completes which task, with what, and through
 * which app (ADR-011 Rule 2 operation object).
 *
 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-an-upload-completion-lands-as-a-file-attachment-on-the-case-object
 */
final class TaskCompletionCommand {

	/**
	 * Constructor.
	 *
	 * @param PortalSubject    $subject    The acting subject.
	 * @param string           $uuid       The task uuid.
	 * @param PortalCompletion $completion What the party submitted: answers, comment, uploads and outcome.
	 * @param string           $viaApp     The app completing the task in-process; empty for the portal itself.
	 */
	public function __construct(
		public readonly PortalSubject $subject,
		public readonly string $uuid,
		public readonly PortalCompletion $completion,
		public readonly string $viaApp,
	) {
	}//end __construct()
}//end class
