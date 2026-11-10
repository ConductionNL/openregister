<?php

/**
 * DependentValueTableInterface: what callers outside the component use of DependentValueTable.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rules
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

namespace OCA\OpenRegister\Service\Rules;

/**
 * Read the dependent-value tables of a schema and name the values they refuse.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface DependentValueTableInterface {

	/**
	 * The refusal code an object save carries when a value is outside the table.
	 *
	 * @var string
	 */
	public const CODE_VALUE_NOT_ALLOWED = 'dependent-value-not-allowed';

	/**
	 * The properties whose tables the object being saved breaks.
	 *
	 * @param array<string, mixed> $properties The schema's properties block.
	 * @param array<string, mixed> $data The object as it would be saved.
	 * @param array<string, mixed> $stored The object as currently stored, or an empty array on create.
	 *
	 * @return array<int, array{property: string, controlledBy: string, value: string, controllingValue: string, message: string}> The violations.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function violations(array $properties, array $data, array $stored=[]): array;

	/**
	 * Whether any property of this schema declares a table at all.
	 *
	 * @param array<string, mixed> $properties The schema's properties block.
	 *
	 * @return bool True when at least one property declares a table.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function declaresAny(array $properties): bool;
}//end interface
