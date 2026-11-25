<?php

/**
 * A portal write changed properties the portal may not change.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/a-property-says-what-the-portal-may-do-with-it/specs/schema-property-exploration/spec.md#scenario-the-resident-changes-only-what-they-may-change
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use RuntimeException;

/**
 * Names every property a portal write was refused for.
 *
 * @spec openspec/changes/a-property-says-what-the-portal-may-do-with-it/specs/schema-property-exploration/spec.md#scenario-the-resident-changes-only-what-they-may-change
 */
class PortalPropertyRefusedException extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param array<int, string> $properties The refused property names.
	 */
	public function __construct(private readonly array $properties) {
		parent::__construct(
			message: 'The portal may not change ' . implode(', ', $properties) . '; nothing was written.'
		);
	}//end __construct()

	/**
	 * The refused property names.
	 *
	 * @return array<int, string> The names.
	 */
	public function getProperties(): array {
		return $this->properties;
	}//end getProperties()
}//end class
