<?php

/**
 * OpenRegister ProseMention
 *
 * A reference somebody wrote in prose: the two objects and the text's anchor.
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
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Relation;

/**
 * One mention in a piece of text: the object the text belongs to, the object
 * it names, the anchor that identifies the text so it can be withdrawn, and
 * the vocabulary key naming the link when there is one.
 *
 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
 */
final readonly class ProseMention {

	/**
	 * One mention.
	 *
	 * @param RelationEndpoint $source       The object the text belongs to, in its register and schema.
	 * @param RelationEndpoint $target       The object the text names, in its register and schema.
	 * @param string           $anchor       What identifies the text, so it can be withdrawn.
	 * @param string|null      $relationType The vocabulary key, when there is one.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function __construct(
		public RelationEndpoint $source,
		public RelationEndpoint $target,
		public string $anchor,
		public ?string $relationType = null,
	) {
	}//end __construct()

	/**
	 * The same mention seen from the object it names: the far side's row.
	 *
	 * @return self The mention with its ends swapped.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function reversed(): self {
		return new self(source: $this->target, target: $this->source, anchor: $this->anchor, relationType: $this->relationType);
	}//end reversed()
}//end class
