<?php

/**
 * DraftRemovalFrame: run a draft removal under a job's name.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

/**
 * The one DraftRemovalRecord frame a background job opens.
 *
 * DraftRemovalRecord writes the audit entry of a removed draft and stays
 * inside lib/Service; a job outside it opens the named-job frame through
 * DraftRemovalFrameInterface, which this class serves by handing straight to
 * DraftRemovalRecord::asJob().
 *
 * @SuppressWarnings(PHPMD.StaticAccess) The frame is DraftRemovalRecord's static frame.
 */
final class DraftRemovalFrame implements DraftRemovalFrameInterface {

	/**
	 * Run a removal as a named job, so its audit entry names that job.
	 *
	 * @param string        $job       The job, by a name an administrator recognises in the job list.
	 * @param callable(): T $operation The removal.
	 *
	 * @return T Whatever the removal returned.
	 *
	 * @template T
	 *
	 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-removing-a-draft-must-leave-one-audit-trail-entry-without-its-content
	 */
	public function asJob(string $job, callable $operation): mixed {
		return DraftRemovalRecord::asJob(job: $job, operation: $operation);
	}//end asJob()
}//end class
