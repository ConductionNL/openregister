<?php

/**
 * Who a department matrix is compiled for, and when.
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
 * The caller's own field values, for `$self`, and the moment `until` is read against.
 *
 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
 */
final class MatrixCaller {
	/**
	 * Set the fields.
	 *
	 * @param array<int, string> $ownValues The caller's own field values, for `$self`.
	 * @param DateTimeInterface  $now       The moment `until` is read against.
	 */
	public function __construct(
		public readonly array $ownValues,
		public readonly DateTimeInterface $now,
	) {
	}//end __construct()
}//end class
