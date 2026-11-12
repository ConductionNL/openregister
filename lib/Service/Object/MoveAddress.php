<?php

/**
 * OpenRegister MoveAddress
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
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
 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * One end of a move: the register and schema an object lives in.
 *
 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md
 */
final class MoveAddress {

	/**
	 * Constructor.
	 *
	 * @param Register $register The register.
	 * @param Schema   $schema   The schema.
	 *
	 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md
	 */
	public function __construct(
		public readonly Register $register,
		public readonly Schema $schema,
	) {
	}//end __construct()

	/**
	 * The address as the ids a move reports.
	 *
	 * @return array{register: int|null, schema: int|null}
	 *
	 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md
	 */
	public function ids(): array {
		return ['register' => $this->register->getId(), 'schema' => $this->schema->getId()];
	}//end ids()
}//end class
