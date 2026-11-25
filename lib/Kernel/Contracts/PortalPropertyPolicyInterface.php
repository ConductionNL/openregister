<?php

/**
 * PortalPropertyPolicyInterface: what a portal may see and change of a schema's objects.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/a-property-says-what-the-portal-may-do-with-it/specs/schema-property-exploration/spec.md#requirement-a-property-declares-what-the-portal-may-do-with-it
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * The read and write rules of the `portal` property key, as callers outside Core reach them.
 *
 * @spec openspec/changes/a-property-says-what-the-portal-may-do-with-it/specs/schema-property-exploration/spec.md#requirement-a-property-declares-what-the-portal-may-do-with-it
 */
interface PortalPropertyPolicyInterface {

	/**
	 * An object as the portal may see it: every property whose `portal` is `none` or absent left out.
	 *
	 * @param array<string, mixed> $properties The schema's property definitions by name.
	 * @param array<string, mixed> $object     The object as the API renders it.
	 *
	 * @return array<string, mixed> The object without the properties the portal may not see.
	 */
	public function readable(array $properties, array $object): array;

	/**
	 * The properties a portal write would change although their `portal` is not `edit`.
	 *
	 * @param array<string, mixed>      $properties The schema's property definitions by name.
	 * @param array<string, mixed>      $incoming   The values the write sends.
	 * @param array<string, mixed>|null $existing   The stored values, null for a create.
	 * @param bool                      $replace    Whether the write replaces the object rather than merging.
	 *
	 * @return array<int, string> The refused property names; empty when the write may go ahead.
	 */
	public function refusedChanges(array $properties, array $incoming, ?array $existing, bool $replace = false): array;
}//end interface
