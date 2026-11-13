<?php

/**
 * The existence subquery being written: where it reads from and which related row it consults.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Query
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Query;

/**
 * One `EXISTS` subquery: the SQL target and the inner row it consults.
 *
 * The target says which engine, table, outer alias and storage the SQL is
 * written for; the inner row says which alias the related row gets, which
 * access predicate restricts it and which prefix its placeholders carry. Every
 * part of the clause (the row conditions, the join and each filter condition)
 * reads both, so they travel together.
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */
final readonly class RelatedRowSubquery {

	/**
	 * Bundle the subquery.
	 *
	 * @param RelatedRowSqlTarget $target The engine, related table, outer alias and storage shape.
	 * @param RelatedRowInnerRow  $inner  The related row's alias, its access predicate and its placeholder prefix.
	 */
	public function __construct(
		public RelatedRowSqlTarget $target,
		public RelatedRowInnerRow $inner,
	) {
	}//end __construct()

	/**
	 * The placeholder name of the filter condition at a position.
	 *
	 * @param int|string $index The condition's key in the filter.
	 *
	 * @return string The placeholder name.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function placeholderFor(int|string $index): string {
		return sprintf('%s_c%d', $this->inner->parameterPrefix, $index);
	}//end placeholderFor()

	/**
	 * A property of the related (inner) row.
	 *
	 * @param string $name The property name.
	 *
	 * @return RelatedRowField The field.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function innerField(string $name): RelatedRowField {
		return new RelatedRowField(alias: $this->inner->alias, name: $name);
	}//end innerField()

	/**
	 * A property of the object (outer) row.
	 *
	 * @param string $name The property name.
	 *
	 * @return RelatedRowField The field.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function outerField(string $name): RelatedRowField {
		return new RelatedRowField(alias: $this->target->outerAlias, name: $name);
	}//end outerField()
}//end class
