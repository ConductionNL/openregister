<?php

/**
 * How the hardening report names one control.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Hardening
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Hardening;

/**
 * The identifier, title, category and enforcer of one hardening control.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Hardening
 */
final class HardeningControlLabel {

	/**
	 * Constructor.
	 *
	 * @param string $id       The control identifier, e.g. `auth.rateLimit.windowSeconds`.
	 * @param string $title    What the control does, in one line.
	 * @param string $category The group it is reported under.
	 * @param string $source   Who enforces it: `platform`, `administered` or `code`.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $title,
		public readonly string $category,
		public readonly string $source,
	) {

	}//end __construct()
}//end class
