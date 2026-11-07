<?php

/**
 * AggregationRunnerInterface: what callers outside the Service component ask of AggregationRunner.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Aggregation;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Search\Service\Aggregation\AggregationQuery;
use RuntimeException;

/**
 * The three entry points the aggregation controller, the threshold evaluator and
 * the report renderer use. Read gate, organisation scope and caching stay inside
 * AggregationRunner; this interface only names the calls.
 *
 * @spec openspec/specs/aggregation-api/spec.md
 */
interface AggregationRunnerInterface {

	/**
	 * Run a named aggregation declared on a schema.
	 *
	 * @param string               $registerRef Register slug/uuid/id: the boundary, resolved first.
	 * @param string               $schemaRef   Schema slug/uuid/id, matched among the register's schemas.
	 * @param string               $name        The aggregation's name in the schema declaration.
	 * @param bool                 $bypassRbac  Internal-system mode: skip the read gate.
	 * @param array<string, mixed> $parentRow   Parent object's values for `@self.<field>` references.
	 * @param array<string, mixed> $extraFilter Constraints that narrow, never relax, the declared filter.
	 *
	 * @return array<string, mixed> The result envelope: name, metric, field, value or groups.
	 *
	 * @throws RuntimeException When the schema/aggregation is missing.
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	public function run(
		string $registerRef,
		string $schemaRef,
		string $name,
		bool $bypassRbac = false,
		array $parentRow = [],
		array $extraFilter = [],
	): array;

	/**
	 * Run an ad-hoc aggregation by register/schema ref.
	 *
	 * @param string           $registerRef Register slug/uuid/id.
	 * @param string           $schemaRef   Schema slug/uuid/id.
	 * @param AggregationQuery $query       The fully-validated query.
	 * @param bool             $bypassRbac  Internal-system mode, as run() has it: skip the read gate.
	 *
	 * @return array<string, mixed> Result envelope.
	 *
	 * @throws RuntimeException When the register or schema cannot be resolved.
	 * @throws NotAuthorizedException When the caller may not read the schema.
	 *
	 * @spec openspec/specs/aggregations-backend-native/spec.md
	 */
	public function runAdhocByRef(
		string $registerRef,
		string $schemaRef,
		AggregationQuery $query,
		bool $bypassRbac=false,
	): array;

	/**
	 * Load a schema by ref, register-scoped when a register ref is given.
	 *
	 * @param string      $schemaRef   Schema slug/uuid/id.
	 * @param string|null $registerRef Optional register slug/uuid/id — when given, the boundary.
	 *
	 * @return Schema The loaded schema.
	 *
	 * @throws RuntimeException When the schema can't be found, or is not carried by the register.
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	public function findSchema(string $schemaRef, ?string $registerRef = null): Schema;
}//end interface
