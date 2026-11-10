<?php

/**
 * OpenRegister expired draft tally
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
 * What the reads of one register and schema's expired drafts found so far: the uuids read, the drafts left unread, and the pages still allowed.
 *
 * Built by DraftExpiry::expiredIn() and never leaves DraftExpiry.
 *
 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-one-background-job-must-remove-drafts-past-their-schemas-lifetime
 */
final class ExpiredDraftTally {

	/**
	 * The uuids of the expired drafts read so far, in read order.
	 *
	 * @var array<int, string>
	 */
	public array $expired = [];

	/**
	 * The expired drafts counted but left unread beyond the read cap.
	 *
	 * @var int
	 */
	public int $unread = 0;

	/**
	 * Constructor.
	 *
	 * @param int $pages The full pages the reads may still take.
	 */
	public function __construct(
		public int $pages,
	) {
	}//end __construct()
}//end class
