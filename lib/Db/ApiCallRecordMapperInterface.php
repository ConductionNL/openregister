<?php

/**
 * ApiCallRecordMapperInterface: what callers outside the Db component use of ApiCallRecordMapper.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
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

namespace OCA\OpenRegister\Db;

use OCA\OpenRegister\Db\ApiCall\ApiCallPeriod;
use DateTime;

/**
 * The methods of ApiCallRecordMapper that callers outside the Db component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to ApiCallRecordMapper. Guarded by C04InterfaceBindingsTest.
 */
interface ApiCallRecordMapperInterface {

	/**
	 * Count one call against a caller, route, method and version.
	 *
	 * Tries the increment first and inserts only when nothing was updated, so
	 * the common path (a caller that has called before) is one statement. A
	 * concurrent insert of the same combination loses the unique index race and
	 * is retried as an increment, which is why the insert failure is not simply
	 * swallowed.
	 *
	 * @param ApiCallKey $key    The caller, route, method and version.
	 * @param DateTime   $moment When the call happened.
	 *
	 * @return bool True when the call was counted.
	 *
	 * @spec openspec/specs/api-surface-governance/spec.md#requirement-every-api-call-records-its-caller-and-a-caller-carries-a-limit-and-an-address-binding-req-avs-003
	 */
	public function count(ApiCallKey $key, DateTime $moment): bool;

	/**
	 * The records whose last call falls in a period.
	 *
	 * @param ApiCallPeriod $period The period, the optional contract version and the row cap.
	 *
	 * @return array<int, ApiCallRecord> The records, busiest first.
	 *
	 * @spec openspec/specs/api-surface-governance/spec.md#requirement-every-api-call-records-its-caller-and-a-caller-carries-a-limit-and-an-address-binding-req-avs-003
	 */
	public function findInPeriod(ApiCallPeriod $period): array;

	/**
	 * The principals that called any of the given route prefixes since a moment, busiest first.
	 *
	 * @param array<int, string> $prefixes The route prefixes.
	 * @param DateTime           $from     The earliest last call.
	 * @param int                $limit    The most principals to return.
	 *
	 * @return array<int, array{principal: string, calls: int, lastSeen: string|null}> The callers.
	 *
	 * @spec openspec/changes/schema-breaking-change-notice/specs/schema-migration/spec.md#requirement-the-breaking-change-gate-shows-who-will-be-affected
	 */
	public function findCallersOfRoutes(array $prefixes, DateTime $from, int $limit = 500): array;

	/**
	 * How many principals called any of the given route prefixes since a moment.
	 *
	 * @param array<int, string> $prefixes The route prefixes.
	 * @param DateTime           $from     The earliest last call.
	 *
	 * @return int The number of distinct principals.
	 *
	 * @spec openspec/changes/schema-breaking-change-notice/specs/schema-migration/spec.md#requirement-the-breaking-change-gate-shows-who-will-be-affected
	 */
	public function countCallersOfRoutes(array $prefixes, DateTime $from): int;
}//end interface
