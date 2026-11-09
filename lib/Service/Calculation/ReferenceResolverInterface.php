<?php

/**
 * ReferenceResolverInterface: what callers outside the component use of ReferenceResolver.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calculation
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

namespace OCA\OpenRegister\Service\Calculation;

use OCA\OpenRegister\Db\Register;

/**
 * Resolve the references a calculation reads into its payload.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface ReferenceResolverInterface {

	/**
	 * Build the `@ref` map for an object's declared references.
	 *
	 * Each declared reference resolves to at most one object's data array (or
	 * null). The result is intended to be injected into the evaluation payload
	 * as `$payload['@ref']` so calculations can read `@ref.<name>.<field>`.
	 *
	 * @param array<string, mixed> $payload Object data WITH `@self` already injected.
	 * @param array<string, mixed> $references The `x-openregister-references` map.
	 * @param Register|string|int|null $register Saving object's register context.
	 * @param string|null $organisation Saving object's organisation UUID (the tenant boundary).
	 *
	 * @return array<string, mixed> Map of reference name to resolved data (or null).
	 *
	 * @spec openspec/changes/calc-engine-reference-lookup/tasks.md#task-2
	 * @spec openspec/changes/calculations-resolve-references-regardless-of-saver/specs/computed-fields/spec.md
	 */
	public function resolveAll(array $payload, array $references, mixed $register, ?string $organisation): array;

	/**
	 * Build the `@ref` map and name the references that could not be resolved.
	 *
	 * `unresolved` lists every reference that had something to resolve (a
	 * filled foreign key, or a lookup that matched rows) and still resolved
	 * empty: the target is missing, outside the saving object's tenant, or the
	 * read failed. An empty foreign key, or a lookup that matched nothing, is a
	 * real answer (null) and is not listed.
	 *
	 * @param array<string, mixed> $payload Object data WITH `@self` already injected.
	 * @param array<string, mixed> $references The `x-openregister-references` map.
	 * @param Register|string|int|null $register Saving object's register context.
	 * @param string|null $organisation Saving object's organisation UUID (the tenant boundary).
	 *
	 * @return array{refs: array<string, mixed>, unresolved: list<string>} The map and the unresolved names.
	 *
	 * @spec openspec/changes/calculations-resolve-references-regardless-of-saver/specs/computed-fields/spec.md
	 */
	public function resolveAllWithOutcome(array $payload, array $references, mixed $register, ?string $organisation): array;
}//end interface
