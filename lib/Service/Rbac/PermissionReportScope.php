<?php

/**
 * PermissionReportScope - which registers and schemas a permission report covers
 *
 * The audit reports take an optional `register` / `schema` filter and otherwise
 * cover everything. Both resolutions have the same shape and the same tolerance
 * — an unknown filter is an empty report, not a 500 — and both were private
 * helpers on the controller, which paid four dependencies (two mappers and two
 * entity types) for them.
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
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;

/**
 * Resolves the entities a permission report runs over.
 */
class PermissionReportScope {
	/**
	 * Constructor.
	 *
	 * @param RegisterMapper $registerMapper Register lookup.
	 * @param SchemaMapper   $schemaMapper   Schema lookup.
	 */
	public function __construct(
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
	) {
	}//end __construct()

	/**
	 * The registers the report covers.
	 *
	 * @param string|null $filter Optional register filter (id|uuid|slug).
	 *
	 * @return Register[] The registers, empty when the filter matches nothing.
	 *
	 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
	 */
	public function registers(?string $filter): array {
		try {
			if ($filter !== null && $filter !== '') {
				// The mapper throws when nothing matches; find() never answers
				// null. The catch below is what turns an unknown filter into an
				// empty report rather than a 500.
				return [$this->registerMapper->find($filter)];
			}

			return $this->registerMapper->findAll();
		} catch (\Throwable $e) {
			unset($e);
			return [];
		}
	}//end registers()

	/**
	 * The schemas the report covers.
	 *
	 * @param string|null $filter Optional schema filter (id|uuid|slug).
	 *
	 * @return Schema[] The schemas, empty when the filter matches nothing.
	 *
	 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
	 */
	public function schemas(?string $filter): array {
		try {
			if ($filter !== null && $filter !== '') {
				// See registers(): the mapper throws rather than answering null.
				return [$this->schemaMapper->find($filter)];
			}

			return $this->schemaMapper->findAll();
		} catch (\Throwable $e) {
			unset($e);
			return [];
		}
	}//end schemas()
}//end class
