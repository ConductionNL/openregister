<?php

/**
 * The grants report an object's access-review endpoint answers with.
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
 * @spec openspec/changes/an-object-lists-its-grants-with-their-origin/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The grants report an object's access-review endpoint answers with.
 *
 * Bound to ObjectGrantsReport in ContractBindings. Every method is described on ObjectGrantsReport.
 */
interface ObjectGrantsReportInterface {

	/**
	 * The grant rows of one object.
	 *
	 * @param ObjectEntity   $object The object, already resolved and gated.
	 * @param GrantRowsQuery $query  The register and schema as the request named them, and the principal types to keep.
	 *
	 * @return array{object: string|null, results: array<int, array<string, mixed>>, denyEnforcement: string}
	 *         The rows.
	 *
	 * @throws \RuntimeException When a share source cannot be read.
	 *
	 * @spec openspec/changes/an-object-lists-its-grants-with-their-origin/specs/rbac-scopes/spec.md
	 */
	public function rowsFor(ObjectEntity $object, GrantRowsQuery $query): array;
}//end interface
