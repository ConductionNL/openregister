<?php

/**
 * An object together with the register and schema the caller addressed it by.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Interaction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Interaction;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The object a follow or a sighting hangs on, with the register and schema
 * as the caller addressed it (ADR-011 Rule 2).
 *
 * The register and schema are the strings of the route, kept as given and
 * each nullable; they are stored on the row, never resolved here. The object
 * was already resolved through RBAC by the caller.
 *
 * @spec openspec/specs/object-interactions/spec.md
 */
final class AddressedObject {
	/**
	 * Constructor.
	 *
	 * @param ObjectEntity $object   The object, already resolved through RBAC.
	 * @param string|null  $register The register as the caller addressed it.
	 * @param string|null  $schema   The schema as the caller addressed it.
	 */
	public function __construct(
		public readonly ObjectEntity $object,
		public readonly ?string $register = null,
		public readonly ?string $schema = null,
	) {
	}//end __construct()
}//end class
