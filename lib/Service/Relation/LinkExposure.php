<?php

/**
 * What a link hands over when it crosses a domain boundary (row 13.36).
 *
 * The ledger note: "Row 2.26 declares typed links. A link is all or nothing,
 * so relating a Wmo case to a Jeugdwet case exposes everything or nothing
 * rather than the two fields that were meant."
 *
 * 🔴 EXPOSURE NARROWS, IT NEVER WIDENS (D-4). A link is not a grant. The
 * alternative — a link that hands over whatever its schema author listed —
 * turns every relation into an access-control decision made by whoever wrote
 * the schema, and a property-level rule refusing a field would be defeated by
 * anybody willing to add a relation type.
 *
 * 🔑 THE DESIGN'S TWO SENTENCES ONLY LOOK CONTRADICTORY, so this is where the
 * reading is written down. D-4 says the exposed set is "the intersection of
 * what the link offers and what the reader could see", and the proposal says
 * "a reader holding NO other access to the far record sees exactly those
 * properties". Read as an intersection with OBJECT access those cannot both
 * hold: a reader with no object access would see nothing. The intersection is
 * with the PROPERTY-level rules, which is the only reading under which both
 * sentences are true — the link supplies the reach to the record, and the
 * property rules still say which of its fields this reader may see. So a
 * link can carry a reader to a record they could not otherwise open, and can
 * never show them a field their own rules withhold.
 *
 * 🔴 AND A PROPERTY OUTSIDE THE SET READS AS WITHHELD, NOT ABSENT (D-5).
 * Empty reads as "there is no besluit"; withheld reads as "you may not see
 * it". `objects-as-the-hinge-between-cases` made the same choice for the same
 * reason, and the two facts send a reader to different places.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Relation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/relations-that-travel-and-what-they-expose/specs/referential-integrity/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Relation;

/**
 * Narrows a far record to the properties a link type declares it exposes.
 *
 * @spec openspec/changes/relations-that-travel-and-what-they-expose/specs/referential-integrity/spec.md
 */
class LinkExposure {

	/**
	 * The key a relation type declares its field set under.
	 *
	 * @var string
	 */
	public const KEY = 'exposes';

	/**
	 * What a property outside the exposed set reads as.
	 *
	 * A marker rather than an omission, because omitting it makes "you may not
	 * see this" indistinguishable from "this record does not have one".
	 *
	 * @var string
	 */
	public const WITHHELD = '__withheld__';

	/**
	 * Whether a relation type declares a field set at all.
	 *
	 * 🔴 AN UNDECLARED `exposes` MEANS THE LINK NARROWS NOTHING — the behaviour
	 * every relation type has today, and the one every existing schema must
	 * keep. A PRESENT-BUT-EMPTY `exposes` means it exposes NOTHING, which is a
	 * different statement and a legitimate one: a link that says "this record
	 * is related, and you may see none of it". Reading the two the same way is
	 * how a list that filters nothing becomes a list that grants everything.
	 *
	 * @param array<string, mixed> $relationType The relation type descriptor.
	 *
	 * @return bool True when the type declares a set.
	 *
	 * @spec openspec/changes/relations-that-travel-and-what-they-expose/specs/referential-integrity/spec.md
	 */
	public function declaresExposure(array $relationType): bool {
		return (array_key_exists(self::KEY, $relationType) === true && is_array($relationType[self::KEY]) === true);
	}//end declaresExposure()

	/**
	 * The properties this reader may see through this link.
	 *
	 * @param array<string, mixed> $relationType The relation type descriptor.
	 * @param array<int, string>   $readable     The properties the reader's own rules allow on the far schema.
	 *
	 * @return array<int, string> The visible properties.
	 *
	 * @spec openspec/changes/relations-that-travel-and-what-they-expose/specs/referential-integrity/spec.md
	 */
	public function visibleProperties(array $relationType, array $readable): array {
		if ($this->declaresExposure(relationType: $relationType) === false) {
			return array_values($readable);
		}

		$declared = array_map(static fn (mixed $p): string => (string)$p, $relationType[self::KEY]);

		// The intersection, in the DECLARED order, so a surface renders the
		// fields in the order the schema author listed them rather than in
		// whatever order the permission layer happened to answer.
		$visible = [];
		foreach ($declared as $property) {
			if (in_array($property, $readable, true) === true) {
				$visible[] = $property;
			}
		}

		return $visible;
	}//end visibleProperties()

	/**
	 * The far record as this reader sees it through this link.
	 *
	 * @param array<string, mixed> $farObject    The far record.
	 * @param array<string, mixed> $relationType The relation type descriptor.
	 * @param array<int, string>   $readable     The properties the reader's own rules allow.
	 *
	 * @return array<string, mixed> The projection, with withheld properties marked.
	 *
	 * @spec openspec/changes/relations-that-travel-and-what-they-expose/specs/referential-integrity/spec.md
	 */
	public function project(array $farObject, array $relationType, array $readable): array {
		$visible = $this->visibleProperties(relationType: $relationType, readable: $readable);

		$projection = [];
		foreach (array_keys($farObject) as $property) {
			$property = (string)$property;
			if (in_array($property, $visible, true) === true) {
				$projection[$property] = $farObject[$property];
				continue;
			}

			$projection[$property] = self::WITHHELD;
		}

		return $projection;
	}//end project()

	/**
	 * Why a declared `exposes` may not be saved, or null when it may.
	 *
	 * @param array<string, mixed> $relationType    The relation type descriptor.
	 * @param array<int, string>   $farProperties   The far schema's declared properties.
	 * @param string               $typeName        The type, for the message.
	 *
	 * @return string|null The reason.
	 *
	 * @spec openspec/changes/relations-that-travel-and-what-they-expose/specs/referential-integrity/spec.md
	 */
	public function refusalFor(array $relationType, array $farProperties, string $typeName): ?string {
		if ($this->declaresExposure(relationType: $relationType) === false) {
			return null;
		}

		foreach ($relationType[self::KEY] as $property) {
			$property = (string)$property;

			if ($property === '') {
				return sprintf('relation type "%s" exposes an unnamed property', $typeName);
			}

			// Refused at SAVE, because a name that matches nothing is silently
			// absent from every projection afterwards — the author sees a 200
			// and a link that exposes one field fewer than they wrote.
			if (in_array($property, $farProperties, true) === false) {
				return sprintf(
					'relation type "%s" exposes "%s", which the linked schema does not declare',
					$typeName,
					$property
				);
			}
		}

		return null;
	}//end refusalFor()
}//end class
