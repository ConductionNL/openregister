<?php

/**
 * OpenRegister AggregationSlot
 *
 * The place one aggregation result occupies in the aggregation cache.
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
 * @spec openspec/specs/aggregations-backend-native/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Aggregation;

use OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef;

/**
 * Which cached aggregation: the register and schema it counts, its name and its resolved filter.
 *
 * The caller's RBAC scope is not part of it; AggregationCache folds that in when it derives the key.
 *
 * @spec openspec/specs/aggregations-backend-native/spec.md
 */
final class AggregationSlot {
	/**
	 * Name a slot.
	 *
	 * @param string               $registerSlug Register slug.
	 * @param string               $schemaSlug   Schema slug.
	 * @param string               $name         Aggregation name (or the ad-hoc name).
	 * @param array<string, mixed> $filter       Resolved filter (placeholders concrete).
	 */
	public function __construct(
		public readonly string $registerSlug,
		public readonly string $schemaSlug,
		public readonly string $name,
		public readonly array $filter,
	) {
	}//end __construct()

	/**
	 * The slot of an ad-hoc aggregation: named after a hash of the whole query, so two
	 * different ad-hoc queries never share a slot and never collide with a named one.
	 *
	 * @param RegisterSchemaRef $table The register and schema slugs.
	 * @param AggregationQuery  $query The ad-hoc query.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/aggregations-backend-native/spec.md
	 */
	public static function fromAdhocQuery(RegisterSchemaRef $table, AggregationQuery $query): self {
		$encoded = json_encode($query->toArray());
		$encodedStr = '';
		if ($encoded !== false) {
			$encodedStr = $encoded;
		}

		return new self(
			registerSlug: (string) $table->register,
			schemaSlug: (string) $table->schema,
			// NOSEMGREP: php.lang.security.weak-crypto.weak-crypto -- The hash only derives a stable identifier, not a security value.
			name: 'adhoc:' . sha1($encodedStr),
			filter: $query->filter
		);
	}//end fromAdhocQuery()
}//end class
