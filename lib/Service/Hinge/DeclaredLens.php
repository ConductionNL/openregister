<?php

/**
 * DeclaredLens — one lens as a schema declares it, under the name it renders as.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Hinge
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Hinge;

/**
 * A declared lens, not yet known to be well-formed (ADR-011 Rule 2 value object).
 */
final class DeclaredLens {

	/**
	 * Constructor.
	 *
	 * @param string $name The property name the lens renders as.
	 * @param mixed  $spec The declaration: an object with `through` and `property` when well-formed.
	 */
	public function __construct(
		public readonly string $name,
		public readonly mixed $spec,
	) {
	}//end __construct()
}//end class
