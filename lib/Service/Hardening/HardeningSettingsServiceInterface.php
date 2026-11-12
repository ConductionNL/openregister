<?php

/**
 * The write path for administered hardening controls and their floors.
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

use InvalidArgumentException;

/**
 * The write path for administered hardening controls and their floors.
 *
 * Bound to HardeningSettingsService in ContractBindings. Every method is described on HardeningSettingsService.
 */
interface HardeningSettingsServiceInterface {

	/**
	 * Change one administered control.
	 *
	 * @param string $control The control identifier.
	 * @param int $value The value asked for.
	 *
	 * @return int The value now in force.
	 *
	 * @throws InvalidArgumentException When the control is not one this instance administers.
	 * @throws HardeningFloorException When the value would weaken the control below its floor.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-the-instance-reports-every-control-against-a-declared-floor-and-refuses-a-change-that-weakens-one-req-ihc-006
	 */
	public function setControl(string $control, int $value): int;

	/**
	 * Replace the origin allowlist.
	 *
	 * @param array<int, string> $origins The origins a browser may read a public answer from.
	 *
	 * @return array<int, string> The allowlist now in force.
	 *
	 * @throws InvalidArgumentException When an entry is not a bare scheme, host and port.
	 * @throws HardeningFloorException When emptying the list would cross the declared floor.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-the-instance-reports-every-control-against-a-declared-floor-and-refuses-a-change-that-weakens-one-req-ihc-006
	 */
	public function setAllowedOrigins(array $origins): array;

	/**
	 * Declare a floor for one control.
	 *
	 * @param string $control The control identifier.
	 * @param int $floor The floor asked for.
	 *
	 * @return int The floor now in force.
	 *
	 * @throws InvalidArgumentException When the control is not one this instance reports.
	 * @throws HardeningFloorException When the floor would be weaker than the shipped baseline.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-the-instance-reports-every-control-against-a-declared-floor-and-refuses-a-change-that-weakens-one-req-ihc-006
	 */
	public function setFloor(string $control, int $floor): int;
}//end interface
