<?php

/**
 * InheritedReference — one declared geo-inheriting reference and its value on a record.
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
 * A schema's geo-inheritance declaration for one property, with what the
 * inheriting record holds there (ADR-011 Rule 2 value object).
 */
final class InheritedReference {

	/**
	 * Constructor.
	 *
	 * @param array $source The declared reference property (`through`) with its optional `label`.
	 * @param mixed $value  The reference property's value on the inheriting record.
	 */
	public function __construct(
		public readonly array $source,
		public readonly mixed $value,
	) {
	}//end __construct()
}//end class
