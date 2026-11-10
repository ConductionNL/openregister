<?php

/**
 * BulkSaveOptions
 *
 * The per-call switches of one bulk save: its default register and schema, and whether
 * it validates, dispatches events and writes audit rows.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

namespace OCA\OpenRegister\Service\Object;

/**
 * How one SaveObjects::saveObjects() call saves its rows, apart from its RBAC and multitenancy flags.
 *
 * No strict_types: SaveObjects declares none, and these values arrive from it.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class BulkSaveOptions {

	/**
	 * Constructor.
	 *
	 * @param BulkBatchDefaults $defaults   The register and schema for every row that names none.
	 * @param bool              $validation Whether to validate rows against their schema.
	 * @param bool              $events     Whether to dispatch object lifecycle events.
	 * @param bool              $audit      Whether to write audit trail rows for the persisted objects.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly BulkBatchDefaults $defaults=new BulkBatchDefaults(),
		public readonly bool $validation=false,
		public readonly bool $events=false,
		public readonly bool $audit=true,
	) {
	}//end __construct()
}//end class
