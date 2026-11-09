<?php

/**
 * One compilation of a department matrix into rules for one caller.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use DateTimeInterface;

/**
 * The caller's own values, the matrix field, the moment, and the scopes
 * gathered so far per action and group.
 *
 * Built and consumed by DepartmentMatrixCompiler only; it never leaves that class.
 *
 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
 */
final class MatrixCompileRun {

	/**
	 * Action => key => scope, as the rows are gathered.
	 *
	 * @var array<string, array<string, array{group: string, where: array<string, mixed>, marker: array<string, mixed>, values: array<int, string>}>>
	 */
	public array $gathered = [];

	/**
	 * Start the run.
	 *
	 * @param array<int, string> $ownValues The caller's own field values, for `$self`.
	 * @param string             $field     The record field the matrix matches on.
	 * @param DateTimeInterface  $now       The moment `until` is read against.
	 */
	public function __construct(
		public readonly array $ownValues,
		public readonly string $field,
		public readonly DateTimeInterface $now,
	) {
	}//end __construct()
}//end class
