<?php

/**
 * PropertyCalculationsInterface: what callers outside the component use of PropertyCalculations.
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

/**
 * Declares only what callers outside the component use of PropertyCalculations; the rest
 * of the class stays its own.
 */
interface PropertyCalculationsInterface {

	/**
	 * Collect the property-level declarations out of a schema's properties.
	 *
	 * @param array<string, mixed> $properties The schema's `properties` map.
	 *
	 * @return array<string, array<string, mixed>> Declarations keyed by property name.
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	public function fromProperties(array $properties): array;

	/**
	 * Merge the forwarded declarations over the schema-level annotation.
	 *
	 * @param array<string, mixed> $annotation The `x-openregister-calculations` map (may be empty).
	 * @param array<string, mixed> $properties The schema's `properties` map.
	 *
	 * @return array<string, mixed> The one calculations map, or an empty array when neither declares anything.
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	public function merge(array $annotation, array $properties): array;

	/**
	 * The names declared in both places at once.
	 *
	 * A name declared twice has two expressions and no rule says which wins, so
	 * the save refuses rather than picking one.
	 *
	 * @param array<string, mixed> $annotation The `x-openregister-calculations` map.
	 * @param array<string, mixed> $properties The schema's `properties` map.
	 *
	 * @return array<int, string> The duplicated names.
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	public function duplicates(array $annotation, array $properties): array;

	/**
	 * Pick out the errors a forwarded property declaration is answerable for.
	 *
	 * An error names the calculation it belongs to, so attribution is by name:
	 * an error mentioning a forwarded name blocks the save, and the rest stay
	 * advisory so a register file cannot break its own import. A duplicate
	 * declaration blocks on its own code, and `dependsOn` is never blocking
	 * because it is a note to the author, not a broken expression.
	 *
	 * @param array<int, array{code: string, message: string}> $errors Every validation error.
	 * @param array<int, string> $forwarded The names declared on a property.
	 *
	 * @return array<int, array{code: string, message: string}> The blocking errors, in input order.
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	public function blockingErrors(array $errors, array $forwarded): array;
}//end interface
