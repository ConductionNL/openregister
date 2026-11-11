<?php

/**
 * ReviewOutcomeServiceInterface: what callers outside the component use of ReviewOutcomeService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
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

namespace OCA\OpenRegister\Service\Archival;

use RuntimeException;

/**
 * The review outcome the archival endpoints apply.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface ReviewOutcomeServiceInterface {

	/**
	 * Carry out a review answer against the record it is about.
	 *
	 * @param string         $entryUuid The uuid of the record.
	 * @param ReviewerAnswer $given     The answer (one of DestructionReviewService::ANSWERS); the reason, recorded
	 *                                  on the record too; the new archiefactiedatum, for a retention; and the
	 *                                  reviewer, written onto the record's own outcome for a transfer.
	 *
	 * @return string|null The uuid of the transfer list a transfer created, null for the other answers.
	 *
	 * @throws RuntimeException When the record cannot be read, moved or handed over.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	public function apply(string $entryUuid, ReviewerAnswer $given): ?string;
}//end interface
