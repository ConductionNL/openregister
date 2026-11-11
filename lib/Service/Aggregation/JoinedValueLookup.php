<?php

/**
 * JoinedValueLookup
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Aggregation;

/**
 * The joined side's values, ready to hang on the parent groups: the join map,
 * the select aliases and the values by merge key.
 *
 * Built by AggregationRunner for one join; it never leaves that class.
 *
 * @spec openspec/specs/aggregation-api/spec.md
 */
final class JoinedValueLookup {
	/**
	 * Constructor.
	 *
	 * @param array<string, string>                        $onMap   Ordered parentField => joinedField map.
	 * @param array<int, string>                           $aliases The select aliases, in declaration order.
	 * @param array<string, array<string, int|float|null>> $values  Joined values by merge key.
	 */
	public function __construct(
		public readonly array $onMap,
		public readonly array $aliases,
		public readonly array $values,
	) {
	}//end __construct()
}//end class
