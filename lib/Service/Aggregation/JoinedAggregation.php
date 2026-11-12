<?php

/**
 * OpenRegister JoinedAggregation
 *
 * The joined side of one aggregation `join`: the schema named by `through`
 * and its register, the join key, the select entries and the filter the
 * joined rows are read with.
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
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Aggregation;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * Carries the joined side of an aggregation join through AggregationRunner.
 *
 * Built only after the joined schema's read gate has passed.
 *
 * @spec openspec/specs/aggregations-backend-native/spec.md
 */
final class JoinedAggregation {

	/**
	 * Parent group field => joined field.
	 *
	 * @var array<string, string>
	 */
	public array $onMap = [];

	/**
	 * The parsed select entries: alias, field, metric.
	 *
	 * @var list<array{alias: string, field: string, metric: string}>
	 */
	public array $selectEntries = [];

	/**
	 * The joined filter: declared, placeholder-resolved, narrowed by the caller.
	 *
	 * @var array<string, mixed>
	 */
	public array $filter = [];

	/**
	 * Constructor.
	 *
	 * @param string   $through  The `through` schema ref.
	 * @param Schema   $schema   The joined schema.
	 * @param Register $register The register that carries it.
	 * @param bool     $rowRbac  Apply the caller's read-rule row predicate.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/aggregations-backend-native/spec.md
	 */
	public function __construct(
		public readonly string $through,
		public readonly Schema $schema,
		public readonly Register $register,
		public readonly bool $rowRbac,
	) {
	}//end __construct()

	/**
	 * The joined fields, in join-key order.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/specs/aggregations-backend-native/spec.md
	 */
	public function getJoinedFields(): array {
		return array_values($this->onMap);
	}//end getJoinedFields()

	/**
	 * Whether the join key spans more than one field.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/aggregations-backend-native/spec.md
	 */
	public function isMultiKey(): bool {
		return count($this->getJoinedFields()) > 1;
	}//end isMultiKey()

	/**
	 * The select aliases, in select order.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/specs/aggregations-backend-native/spec.md
	 */
	public function getAliases(): array {
		return array_map(static fn (array $entry): string => $entry['alias'], $this->selectEntries);
	}//end getAliases()
}//end class
