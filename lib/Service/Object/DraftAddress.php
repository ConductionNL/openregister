<?php

/**
 * Where a draft lives: its register, its schema and its uuid.
 *
 * Every step of making a draft definitive (decision 216) reads the draft at
 * this one address, so the three values travel together.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-a-drafts-owner-must-be-able-to-make-it-definitive-through-the-object-api
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * A draft's register, schema and uuid.
 *
 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-a-drafts-owner-must-be-able-to-make-it-definitive-through-the-object-api
 */
final class DraftAddress {

	/**
	 * Constructor.
	 *
	 * @param Register $register The draft's register.
	 * @param Schema   $schema   The draft's schema.
	 * @param string   $draftId  The draft's uuid; empty names no draft.
	 */
	public function __construct(
		public readonly Register $register,
		public readonly Schema $schema,
		public readonly string $draftId,
	) {
	}//end __construct()
}//end class
