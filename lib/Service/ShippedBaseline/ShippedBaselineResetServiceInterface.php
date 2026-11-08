<?php

/**
 * ShippedBaselineResetServiceInterface: the part of ShippedBaselineResetService that the shipped baseline controller uses.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ShippedBaseline
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/shipped-baseline-reset-is-reachable/specs/schema-import/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ShippedBaseline;

/**
 * The methods of ShippedBaselineResetService that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface ShippedBaselineResetServiceInterface {

	/**
	 * What resetting one part would change. Writes nothing.
	 *
	 * @param string|int $schema The schema id, uuid or slug.
	 * @param string     $path   The part, as a dotted path (`authorization.read`).
	 *
	 * @return array{applicable: bool, reason: string, schema: string, schemaId: int|null, path: string, from: mixed, to: mixed}
	 *         The preview.
	 *
	 * @spec openspec/changes/shipped-baseline-reset-is-reachable/specs/schema-import/spec.md
	 */
	public function preview(string|int $schema, string $path): array;

	/**
	 * Reset one part to the shipped value, write it, and put it on the trail.
	 *
	 * The actor is whoever is signed in; the guard refuses without one. The
	 * audit row is written only once the schema write succeeded.
	 *
	 * @param string|int $schema The schema id, uuid or slug.
	 * @param string     $path   The part, as a dotted path (`authorization.read`).
	 *
	 * @return array{applied: bool, reason: string, schema: string, schemaId: int|null, path: string, from: mixed, to: mixed}
	 *         The outcome.
	 *
	 * @spec openspec/changes/shipped-baseline-reset-is-reachable/specs/schema-import/spec.md
	 */
	public function reset(string|int $schema, string $path): array;
}//end interface
