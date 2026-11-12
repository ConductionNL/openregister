<?php

/**
 * Where a created object came from: the entry and the kind of link.
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
 * The provenance of a split or derived object: the entry of the source it came
 * out of, and the vocabulary key naming the link.
 *
 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
 */
final class ProvenanceLink {
	/**
	 * The provenance.
	 *
	 * @param string|null $entry        The entry of the source it came out of.
	 * @param string|null $relationType The vocabulary key naming the link.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function __construct(
		public readonly ?string $entry = null,
		public readonly ?string $relationType = null,
	) {
	}//end __construct()
}//end class
