<?php

/**
 * ShippedConflictServiceInterface: what the shipped conflict controller uses of ShippedConflictService.
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
 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ShippedBaseline;

/**
 * The conflicts an app upgrade kept local, and an administrator's acceptance of the shipped definition for one.
 *
 * Callers depend on this interface, not on the class.
 *
 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
 */
interface ShippedConflictServiceInterface {

	/**
	 * The parts the last guarded import of a schema kept local because the instance and the app both changed them.
	 *
	 * @param string|int $schema The schema id, uuid or slug.
	 *
	 * @return array<string, mixed> The pending conflicts (`conflicts`: `{path, shipped, live}` each) and
	 *                              the release that reported them (`schema`, `schemaId`, `app`, `appVersion`, `recordedAt`).
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md#requirement-an-upgrade-never-silently-discards-a-local-change-req-lca-003
	 */
	public function pending(string|int $schema): array;

	/**
	 * Take the shipped definition for one conflicting part, write it, and put the decision on the trail.
	 *
	 * The actor is whoever is signed in; without one nothing is written.
	 *
	 * @param string|int $schema The schema id, uuid or slug.
	 * @param string     $path   The conflicting part, as a dotted path (`properties.level`).
	 *
	 * @return array{applied: bool, reason: string, schema: string, schemaId: int|null, path: string, from: mixed, to: mixed}
	 *         The outcome.
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md#requirement-an-upgrade-never-silently-discards-a-local-change-req-lca-003
	 */
	public function accept(string|int $schema, string $path): array;
}//end interface
