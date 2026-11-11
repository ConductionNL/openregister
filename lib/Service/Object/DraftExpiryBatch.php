<?php

/**
 * OpenRegister draft expiry batch
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
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

namespace OCA\OpenRegister\Service\Object;

/**
 * What one schema gives a sweep run: its expired drafts, how many more lie
 * beyond what one run reads, and how many delete attempts the run has left.
 *
 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-one-background-job-must-remove-drafts-past-their-schemas-lifetime
 */
final class DraftExpiryBatch {
	/**
	 * Name the batch.
	 *
	 * @param array<int, string> $expired The uuids of the schema's expired drafts.
	 * @param int                $unread  How many expired drafts lie beyond what one run reads.
	 * @param int                $budget  How many delete attempts are left in this run.
	 */
	public function __construct(
		public readonly array $expired,
		public readonly int $unread,
		public readonly int $budget,
	) {
	}//end __construct()
}//end class
