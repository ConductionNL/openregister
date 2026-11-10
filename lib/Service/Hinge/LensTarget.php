<?php

/**
 * LensTarget — what one lens reads: the referenced record and the property on it.
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
 * The record a lens looks through to and the property it reads there
 * (ADR-011 Rule 2 value object).
 */
final class LensTarget {

	/**
	 * Constructor.
	 *
	 * @param string $reference The referenced record's identifier.
	 * @param mixed  $property  The declared property to read, dot-separated for nesting.
	 */
	public function __construct(
		public readonly string $reference,
		public readonly mixed $property,
	) {
	}//end __construct()
}//end class
