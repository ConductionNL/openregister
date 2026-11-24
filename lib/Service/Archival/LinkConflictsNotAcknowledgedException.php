<?php

/**
 * OpenRegister LinkConflictsNotAcknowledgedException
 *
 * A destroy answer on an entry with link conflicts, without the acknowledgement.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/retention-linked-destruction-conflict/specs/archival-destruction-workflow/spec.md#requirement-destroying-over-a-conflict-needs-an-explicit-acknowledgement
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use DomainException;

/**
 * Carries the conflicts the reviewer would override, for the 422 the controller answers.
 *
 * Its own type, not an InvalidArgumentException: the request is well formed,
 * it only lacks the stated decision to destroy over a conflict (design D-4).
 *
 * @spec openspec/changes/retention-linked-destruction-conflict/specs/archival-destruction-workflow/spec.md#requirement-destroying-over-a-conflict-needs-an-explicit-acknowledgement
 */
class LinkConflictsNotAcknowledgedException extends DomainException {

	/**
	 * Constructor.
	 *
	 * @param array<int, array<string, mixed>> $conflicts The conflicts as they stand now.
	 * @param bool                             $truncated True when the conflicts could not all be established.
	 */
	public function __construct(
		private readonly array $conflicts,
		private readonly bool $truncated,
	) {
		parent::__construct(
			message: 'This record has link conflicts; destroying it needs acknowledgeConflicts: true and a reason'
		);
	}//end __construct()

	/**
	 * The conflicts the reviewer would override.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/retention-linked-destruction-conflict/specs/archival-destruction-workflow/spec.md#requirement-destroying-over-a-conflict-needs-an-explicit-acknowledgement
	 */
	public function getConflicts(): array {
		return $this->conflicts;
	}//end getConflicts()

	/**
	 * Whether the conflicts could not all be established.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/retention-linked-destruction-conflict/specs/archival-destruction-workflow/spec.md#requirement-destroying-over-a-conflict-needs-an-explicit-acknowledgement
	 */
	public function isTruncated(): bool {
		return $this->truncated;
	}//end isTruncated()
}//end class
