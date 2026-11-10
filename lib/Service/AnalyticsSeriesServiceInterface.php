<?php

/**
 * AnalyticsSeriesServiceInterface: what callers outside the component use of AnalyticsSeriesService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use InvalidArgumentException;

/**
 * The analytics series checks and registration the analytics series endpoints call.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface AnalyticsSeriesServiceInterface {

	/**
	 * Authorisation guard for the register (write) path — a series may
	 * only be registered by an authenticated user. Throws fail-closed
	 * (ADR-005) so the controller surfaces 400/401 rather than silently
	 * writing. Called explicitly from the controller so the authz
	 * decision is visible at the endpoint boundary.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When no user is logged in.
	 *
	 * @spec openspec/specs/integration-leaf-foundation/spec.md
	 */
	public function ensureCanRegister(): void;

	/**
	 * Authorisation guard for the fetch (read) path — resolves the series
	 * RBAC-scoped and returns it only when the current user may read it.
	 * Returns null on unknown OR disallowed so the controller answers a
	 * uniform 404 (no enumeration oracle, ADR-005 fail-closed). Called
	 * explicitly from the controller so the authz decision is visible at
	 * the endpoint boundary.
	 *
	 * @param string $seriesKey The series key.
	 *
	 * @return array<string,mixed>|null The render contract, or null.
	 *
	 * @spec openspec/specs/integration-leaf-foundation/spec.md
	 */
	public function ensureReadableOrNull(string $seriesKey): ?array;

	/**
	 * Register (create or replace) a page-level series.
	 *
	 * Upserts by `seriesKey`: re-registering the same key updates the
	 * payload in place so a leaf can refresh its dashboard data without
	 * accumulating duplicates. Also (re)declares the matching page widget
	 * on the IntegrationRegistry so the render layer can discover it.
	 *
	 * @param AnalyticsSeriesDraft $draft The series to register.
	 *
	 * @return array<string,mixed> The stored series render contract.
	 *
	 * @throws InvalidArgumentException On invalid key / visibility / chart type.
	 *
	 * @spec openspec/specs/integration-leaf-foundation/spec.md
	 */
	public function registerSeries(AnalyticsSeriesDraft $draft): array;
}//end interface
