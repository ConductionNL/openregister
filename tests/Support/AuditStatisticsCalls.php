<?php

/**
 * Build AuditTrailMapper::statisticsTotals() scopes and stubs from positional ones.
 *
 * Suites that pinned the former `(registerId, schemaId, exclude)` call keep
 * their recorded arguments: `scope()` builds the scope those three values
 * describe, and `positional()` hands a recorded callback the same three values.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Support
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

namespace OCA\OpenRegister\Tests\Support;

use Closure;
use OCA\OpenRegister\Db\AuditTrail\AuditStatisticsScope;

/**
 * Scopes and stubs for statisticsTotals().
 */
final class AuditStatisticsCalls {
	/**
	 * The scope of one register and schema (null for all), leaving out the given combinations.
	 *
	 * @param int|null                       $registerId The register.
	 * @param int|null                       $schemaId   The schema.
	 * @param array<int, array<string, int>> $exclude    The combinations to leave out.
	 *
	 * @return AuditStatisticsScope The scope.
	 */
	public static function scope(?int $registerId = null, ?int $schemaId = null, array $exclude = []): AuditStatisticsScope {
		$scope = new AuditStatisticsScope(registerId: $registerId, schemaId: $schemaId);
		$scope->exclude = $exclude;

		return $scope;
	}//end scope()

	/**
	 * A statisticsTotals() callback that calls $inner with the scope's register, schema and exclusions.
	 *
	 * @param callable $inner The positional callback.
	 *
	 * @return Closure The statisticsTotals() callback.
	 */
	public static function positional(callable $inner): Closure {
		return static fn (AuditStatisticsScope $scope): mixed => $inner($scope->registerId, $scope->schemaId, $scope->exclude);
	}//end positional()
}//end class
