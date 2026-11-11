<?php

/**
 * The selection a bulk run of an action is started over.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectAction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-selections-run-per-object-and-large-selections-become-a-bulk-job
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectAction;

use OCA\OpenRegister\Db\Schema;

/**
 * The selected objects, with the schema they share and the register and schema as the caller named them.
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-selections-run-per-object-and-large-selections-become-a-bulk-job
 */
final readonly class ObjectActionSelection {

	/**
	 * Describe the selection.
	 *
	 * @param Schema       $schema    The selection's schema.
	 * @param string       $register  The register as the caller named it.
	 * @param string       $schemaRef The schema as the caller named it.
	 * @param list<string> $ids       The selected object ids.
	 */
	public function __construct(
		public Schema $schema,
		public string $register,
		public string $schemaRef,
		public array $ids,
	) {
	}//end __construct()
}//end class
