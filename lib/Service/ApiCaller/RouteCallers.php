<?php
/**
 * RouteCallers: the recorded callers of API routes, read from the caller record.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ApiCaller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ApiCaller;

use OCA\OpenRegister\Db\ApiCallRecordMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\RouteCallersInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\RouteCallWindow;

/**
 * Answers a schema change notice's question for route callers from the recorder and its records.
 *
 * @spec openspec/changes/schema-breaking-change-notice/specs/schema-migration/spec.md#requirement-the-breaking-change-gate-shows-who-will-be-affected
 */
final class RouteCallers implements RouteCallersInterface {

	/**
	 * Wire the caller record.
	 *
	 * @param ApiCallRecorder              $recorder Whether the caller record is on.
	 * @param ApiCallRecordMapperInterface $records  The recorded calls.
	 */
	public function __construct(
		private readonly ApiCallRecorder $recorder,
		private readonly ApiCallRecordMapperInterface $records,
	) {
	}//end __construct()

	/**
	 * Whether the caller record is switched on.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/schema-breaking-change-notice/specs/schema-migration/spec.md#requirement-the-breaking-change-gate-shows-who-will-be-affected
	 */
	public function isRecording(): bool {
		return $this->recorder->isEnabled();
	}//end isRecording()

	/**
	 * The principals that called any of the window's routes, busiest first.
	 *
	 * @param RouteCallWindow $window The routes and the earliest last call.
	 * @param int             $limit  The most principals to return.
	 *
	 * @return array<int, array{principal: string, calls: int, lastSeen: string|null}> The callers.
	 *
	 * @spec openspec/changes/schema-breaking-change-notice/specs/schema-migration/spec.md#requirement-the-breaking-change-gate-shows-who-will-be-affected
	 */
	public function callersOf(RouteCallWindow $window, int $limit): array {
		return $this->records->findCallersOfRoutes(prefixes: $window->prefixes, from: $window->from, limit: $limit);
	}//end callersOf()

	/**
	 * How many principals called any of the window's routes.
	 *
	 * @param RouteCallWindow $window The routes and the earliest last call.
	 *
	 * @return int The number of distinct principals.
	 *
	 * @spec openspec/changes/schema-breaking-change-notice/specs/schema-migration/spec.md#requirement-the-breaking-change-gate-shows-who-will-be-affected
	 */
	public function countCallersOf(RouteCallWindow $window): int {
		return $this->records->countCallersOfRoutes(prefixes: $window->prefixes, from: $window->from);
	}//end countCallersOf()
}//end class
