<?php

/**
 * OpenRegister Owned Draft
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Form
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

namespace OCA\OpenRegister\Service\Form;

use OCA\OpenRegister\Service\Object\DraftOwner;

/**
 * One draft by its uuid, together with the owner it must belong to.
 *
 * The pair travels together through every step that reads, merges or
 * promotes a draft: only the owner's own draft is ever found.
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-an-object-must-be-able-to-carry-the-explicit-lifecycle-status-draft
 */
final readonly class OwnedDraft {

	/**
	 * @param DraftOwner|null $owner   The owner; null finds no draft.
	 * @param string          $draftId The draft's uuid.
	 */
	public function __construct(
		public ?DraftOwner $owner,
		public string $draftId,
	) {
	}//end __construct()
}//end class
