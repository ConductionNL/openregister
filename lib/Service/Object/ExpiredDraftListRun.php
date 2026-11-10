<?php

/**
 * OpenRegister expired draft list run
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
 * One list of expired drafts the sweep reads page by page: the register, schema and cutoff, and the list query without paging.
 *
 * Built by DraftExpiry::expiredIn() and never leaves DraftExpiry.
 *
 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-one-background-job-must-remove-drafts-past-their-schemas-lifetime
 */
final class ExpiredDraftListRun {
	/**
	 * Constructor.
	 *
	 * @param DraftExpiryPair      $pair  The register and schema, and the cutoff: drafts last changed before it are expired.
	 * @param array<string, mixed> $query The list query, without paging.
	 */
	public function __construct(
		public readonly DraftExpiryPair $pair,
		public readonly array $query,
	) {
	}//end __construct()
}//end class
