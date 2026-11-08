<?php

/**
 * DepartmentMatrixValidatorInterface: what callers outside the component use of DepartmentMatrixValidator.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
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

namespace OCA\OpenRegister\Service\Rbac;

/**
 * Declares only what callers outside the component use of DepartmentMatrixValidator; the rest
 * of the class stays its own.
 */
interface DepartmentMatrixValidatorInterface {

	/**
	 * The matrix key naming the provider of rows an app adds at check time.
	 *
	 * @var string
	 */
	public const ROWS_FROM_KEY = 'rowsFrom';

	/**
	 * Findings for a matrix declared on a schema.
	 *
	 * @param array<string, mixed> $properties The schema's properties.
	 * @param array<string, mixed>|null $authorization The authorization block.
	 *
	 * @return array<int, array{code: string, message: string}> The findings; empty when valid.
	 *
	 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
	 */
	public function validate(array $properties, ?array $authorization): array;
}//end interface
