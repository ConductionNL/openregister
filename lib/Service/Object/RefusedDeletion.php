<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

/**
 * A deletion a RESTRICT constraint refused: the object, its schema and who asked.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class RefusedDeletion {

	/**
	 * Constructor.
	 *
	 * @param string      $objectUuid The UUID of the object that could not be deleted.
	 * @param string|null $schemaId   The schema ID of the blocked object.
	 * @param string      $userId     The user who attempted the deletion.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly string $objectUuid,
		public readonly ?string $schemaId,
		public readonly string $userId,
	) {
	}//end __construct()
}//end class
