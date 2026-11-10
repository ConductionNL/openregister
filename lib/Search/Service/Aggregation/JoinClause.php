<?php

/**
 * OpenRegister JoinClause
 *
 * The `join` clause of one aggregation entry, with the context its
 * validation needs.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Aggregation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/aggregation-api/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Aggregation;

/**
 * A join under validation: the aggregation name, the join object, the whole
 * aggregation spec and the host schema's declared properties.
 */
final class JoinClause {
	/**
	 * Constructor.
	 *
	 * @param string               $name     Aggregation name (for error messages).
	 * @param array<string, mixed> $join     The raw join object.
	 * @param array<string, mixed> $spec     The raw aggregation spec.
	 * @param array<int, mixed>    $propKeys Declared host-schema property names.
	 */
	public function __construct(
		public readonly string $name,
		public readonly array $join,
		public readonly array $spec,
		public readonly array $propKeys,
	) {
	}//end __construct()
}//end class
