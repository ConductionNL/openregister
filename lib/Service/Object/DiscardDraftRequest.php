<?php

/**
 * OpenRegister discard draft request
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
 * One request to discard an owner's draft: the destination it lives in, its id, and who asks.
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-draft-must-be-able-to-be-discarded-by-its-owner
 */
final class DiscardDraftRequest {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $destination The destination pair (id, uuid, slug or entity).
	 * @param string               $draftId     The draft's uuid.
	 * @param DraftOwner           $owner       The asserted owner.
	 */
	public function __construct(
		public readonly array $destination,
		public readonly string $draftId,
		public readonly DraftOwner $owner,
	) {
	}//end __construct()
}//end class
