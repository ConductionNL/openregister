<?php

/**
 * OpenRegister RevertPoint
 *
 * The point an object is reverted to, and whether the revert overwrites the
 * version number instead of writing a new one.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/content-versioning/spec.md
 */

namespace OCA\OpenRegister\Service\Object;

/**
 * Where a revert goes back to: a moment, an audit trail entry id or a version.
 *
 * @spec openspec/specs/content-versioning/spec.md
 */
final class RevertPoint {

	/**
	 * Constructor.
	 *
	 * @param mixed $until            The point to revert to (DateTime|string|int).
	 * @param bool  $overwriteVersion Whether to overwrite the version.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Boolean needed to control version overwrite behavior
	 */
	public function __construct(
		public readonly mixed $until,
		public readonly bool $overwriteVersion = false,
	) {
	}//end __construct()
}//end class
