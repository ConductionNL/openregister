<?php

/**
 * OpenRegister derivation
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
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Relation;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * A child created under a parent: the link that names it and what it inherited.
 *
 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
 */
final class Derivation {
	/**
	 * Constructor.
	 *
	 * @param ObjectEntity         $parent    The parent object.
	 * @param ObjectEntity         $child     The created child.
	 * @param ProvenanceLink       $link      The vocabulary key naming the link, and the parent entry it came out of.
	 * @param array<string, mixed> $inherited What the child took, as recorded.
	 */
	public function __construct(
		public readonly ObjectEntity $parent,
		public readonly ObjectEntity $child,
		public readonly ProvenanceLink $link = new ProvenanceLink(),
		public readonly array $inherited = [],
	) {
	}//end __construct()
}//end class
