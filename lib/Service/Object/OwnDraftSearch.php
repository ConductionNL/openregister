<?php

/**
 * OpenRegister own draft search
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

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * One search for an owner's newest draft in a destination: where to look, whose drafts, and the values a draft must hold.
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-draft-must-be-able-to-be-owned-by-a-scope-value
 */
final class OwnDraftSearch {
	/**
	 * Constructor.
	 *
	 * @param Register             $register The destination register.
	 * @param Schema               $schema   The destination schema.
	 * @param DraftOwner           $owner    The asserted owner.
	 * @param array<string, mixed> $match    Values the draft's data must hold, such as a form's fixed values.
	 */
	public function __construct(
		public readonly Register $register,
		public readonly Schema $schema,
		public readonly DraftOwner $owner,
		public readonly array $match = [],
	) {
	}//end __construct()
}//end class
