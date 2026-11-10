<?php

/**
 * OpenRegister GroupByMember
 *
 * One member of an aggregation `groupBy` clause, with the context its
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
 * A groupBy member under validation: the aggregation it belongs to, the
 * member value, the members listed before it and the declared properties.
 */
final class GroupByMember {
	/**
	 * Constructor.
	 *
	 * @param string            $name     Aggregation name (for error messages).
	 * @param mixed             $field    The member value as written.
	 * @param array<int, mixed> $earlier  The members listed before this one.
	 * @param array<int, mixed> $propKeys Declared schema property names.
	 */
	public function __construct(
		public readonly string $name,
		public readonly mixed $field,
		public readonly array $earlier,
		public readonly array $propKeys,
	) {
	}//end __construct()
}//end class
