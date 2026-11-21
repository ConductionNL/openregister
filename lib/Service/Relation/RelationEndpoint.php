<?php

/**
 * One end of a relation row: an object, in its register and schema.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Relation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Relation;

/**
 * One end of a relation row: the object's uuid and the ids of its register and schema.
 *
 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
 */
final class RelationEndpoint {
	/**
	 * One end.
	 *
	 * @param string   $uuid     The object's uuid.
	 * @param int|null $register The object's register id, when known.
	 * @param int|null $schema   The object's schema id, when known.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function __construct(
		public readonly string $uuid,
		public readonly ?int $register = null,
		public readonly ?int $schema = null,
	) {
	}//end __construct()
}//end class
