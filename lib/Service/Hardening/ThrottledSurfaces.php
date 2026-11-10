<?php

/**
 * The surfaces that register a failed attempt with Nextcloud's brute-force
 * throttler, named once.
 *
 * WHY A SHARED LIST RATHER THAN A STRING PER CONTROLLER. The hardening report
 * answers "which anonymous surfaces are throttled?", and the only honest way to
 * answer it is to read the same constant the controller passes to
 * `#[BruteForceProtection]`. A list maintained beside the controllers drifts
 * the first time somebody adds a surface, and the report then says six on an
 * instance that has seven, which reads as reassurance.
 *
 * Adding a throttled surface means adding a constant here and naming it in
 * {@see self::ALL} of ThrottledSurfacesInterface. The controller then references the interface, never a literal.
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
 * The throttler actions this app registers attempts against.
 *
 * The action names are constants of ThrottledSurfacesInterface, so a controller
 * outside lib/Service names the interface, not this class.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Hardening
 */
final class ThrottledSurfaces implements ThrottledSurfacesInterface {

	/**
	 * How many surfaces register attempts.
	 *
	 * @return int The count.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-the-instance-reports-every-control-against-a-declared-floor-and-refuses-a-change-that-weakens-one-req-ihc-006
	 */
	public static function count(): int {
		return count(self::ALL);

	}//end count()
}//end class
