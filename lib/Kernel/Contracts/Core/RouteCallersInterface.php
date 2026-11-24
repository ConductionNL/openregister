<?php
/**
 * RouteCallersInterface: who called a set of API routes, as a schema change notice asks it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Core
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Core;

/**
 * The recorded callers of API routes.
 *
 * A schema change notice (Core) shows and tells the callers of a schema's routes; the
 * platform keeps the caller record and binds its answer in its own wiring.
 *
 * @spec openspec/changes/schema-breaking-change-notice/specs/schema-migration/spec.md#requirement-the-breaking-change-gate-shows-who-will-be-affected
 */
interface RouteCallersInterface {

	/**
	 * Whether the caller record is switched on; when it is off, no caller is known.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/schema-breaking-change-notice/specs/schema-migration/spec.md#requirement-the-breaking-change-gate-shows-who-will-be-affected
	 */
	public function isRecording(): bool;

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
	public function callersOf(RouteCallWindow $window, int $limit): array;

	/**
	 * How many principals called any of the window's routes.
	 *
	 * @param RouteCallWindow $window The routes and the earliest last call.
	 *
	 * @return int The number of distinct principals.
	 *
	 * @spec openspec/changes/schema-breaking-change-notice/specs/schema-migration/spec.md#requirement-the-breaking-change-gate-shows-who-will-be-affected
	 */
	public function countCallersOf(RouteCallWindow $window): int;
}//end interface
